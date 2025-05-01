<?php

namespace App\Http\Controllers;

use App\Models\Batch;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class BatchController extends Controller
{
    /**
     * Display a listing of the batches.
     */
    public function index(Request $request)
    {
        $user = auth('api')->user();
        $query = Batch::query();

        // If not admin, only show batches the user is part of
        if ($user->role !== 'admin') {
            $query->whereHas('users', function ($q) use ($user) {
                $q->where('users.id', $user->id);
            });
        }

        // Search by name
        if ($request->has('search')) {
            $query->where('name', 'like', '%' . $request->search . '%');
        }

        // Filter by date range
        if ($request->has('start_date')) {
            $query->where('start_date', '>=', $request->start_date);
        }
        if ($request->has('end_date')) {
            $query->where('end_date', '<=', $request->end_date);
        }

        // Order by
        $orderBy = $request->order_by ?? 'created_at';
        $orderDir = $request->order_dir ?? 'desc';
        $query->orderBy($orderBy, $orderDir);

        // Paginate results
        $batches = $query->withCount(['students', 'instructors', 'classes'])
            ->paginate($request->per_page ?? 15);

        return response()->json([
            'success' => true,
            'data' => $batches
        ]);
    }

    /**
     * Store a newly created batch in storage.
     */
    public function store(Request $request)
    {
        $user = auth('api')->user();

        // Only admins and instructors can create batches
        if ($user->role !== 'admin' && $user->role !== 'instructor') {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Only admins and instructors can create batches.'
            ], 403);
        }

        // Validate request
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        // Create batch
        $batch = Batch::create([
            'name' => $request->name,
            'description' => $request->description,
            'start_date' => $request->start_date,
            'end_date' => $request->end_date,
        ]);

        // If instructor created the batch, add them to it
        if ($user->role === 'instructor') {
            $batch->users()->attach($user->id);
        }

        return response()->json([
            'success' => true,
            'message' => 'Batch created successfully.',
            'data' => $batch
        ], 201);
    }

    /**
     * Display the specified batch.
     */
    public function show($id)
    {
        $user = auth('api')->user();
        $batch = Batch::with(['instructors', 'students'])->findOrFail($id);

        // Check if user has access to this batch
        if ($user->role !== 'admin') {
            $hasAccess = $user->batches()->where('batches.id', $batch->id)->exists();
            
            if (!$hasAccess) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized.'
                ], 403);
            }
        }

        // Get additional stats
        $batch->load('classes');
        $classCount = $batch->classes->count();
        $upcomingClassCount = $batch->classes->where('start_time', '>', now())->count();

        return response()->json([
            'success' => true,
            'data' => [
                'batch' => $batch,
                'stats' => [
                    'total_classes' => $classCount,
                    'upcoming_classes' => $upcomingClassCount,
                    'student_count' => $batch->students->count(),
                    'instructor_count' => $batch->instructors->count(),
                ]
            ]
        ]);
    }

    /**
     * Update the specified batch in storage.
     */
    public function update(Request $request, $id)
    {
        $user = auth('api')->user();
        $batch = Batch::findOrFail($id);

        // Only admins and instructors assigned to the batch can update it
        if ($user->role !== 'admin') {
            $isAssigned = $batch->instructors()->where('users.id', $user->id)->exists();
            
            if (!$isAssigned) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. Only admins and assigned instructors can update this batch.'
                ], 403);
            }
        }

        // Validate request
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        // Update batch
        $batch->update($request->only(['name', 'description', 'start_date', 'end_date']));

        return response()->json([
            'success' => true,
            'message' => 'Batch updated successfully.',
            'data' => $batch
        ]);
    }

    /**
     * Remove the specified batch from storage.
     */
    public function destroy($id)
    {
        $user = auth('api')->user();
        $batch = Batch::findOrFail($id);

        // Only admins can delete batches
        if ($user->role !== 'admin') {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Only admins can delete batches.'
            ], 403);
        }

        // Delete batch
        $batch->delete();

        return response()->json([
            'success' => true,
            'message' => 'Batch deleted successfully.'
        ]);
    }

    /**
     * Add users to a batch.
     */
    public function addUsers(Request $request, $id)
    {
        $user = auth('api')->user();
        $batch = Batch::findOrFail($id);

        // Only admins and instructors assigned to the batch can add users
        if ($user->role !== 'admin') {
            $isAssigned = $batch->instructors()->where('users.id', $user->id)->exists();
            
            if (!$isAssigned) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. Only admins and assigned instructors can add users to this batch.'
                ], 403);
            }
        }

        // Validate request
        $validator = Validator::make($request->all(), [
            'user_ids' => 'required|array',
            'user_ids.*' => 'exists:users,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        // Add users to batch
        $batch->users()->attach($request->user_ids);

        return response()->json([
            'success' => true,
            'message' => 'Users added to batch successfully.'
        ]);
    }

    /**
     * Remove users from a batch.
     */
    public function removeUsers(Request $request, $id)
    {
        $user = auth('api')->user();
        $batch = Batch::findOrFail($id);

        // Only admins and instructors assigned to the batch can remove users
        if ($user->role !== 'admin') {
            $isAssigned = $batch->instructors()->where('users.id', $user->id)->exists();
            
            if (!$isAssigned) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. Only admins and assigned instructors can remove users from this batch.'
                ], 403);
            }
        }

        // Validate request
        $validator = Validator::make($request->all(), [
            'user_ids' => 'required|array',
            'user_ids.*' => 'exists:users,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        // Remove users from batch
        $batch->users()->detach($request->user_ids);

        return response()->json([
            'success' => true,
            'message' => 'Users removed from batch successfully.'
        ]);
    }

    /**
     * Get students in a batch.
     */
    public function getStudents($id)
    {
        $user = auth('api')->user();
        $batch = Batch::findOrFail($id);

        // Check if user has access to this batch
        if ($user->role !== 'admin') {
            $hasAccess = $user->batches()->where('batches.id', $batch->id)->exists();
            
            if (!$hasAccess) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized.'
                ], 403);
            }
        }

        // Get students with attendance statistics
        $students = $batch->students()
            ->withCount([
                'attendanceRecords as total_classes' => function ($query) use ($batch) {
                    $query->whereHas('class', function ($q) use ($batch) {
                        $q->where('batch_id', $batch->id);
                    });
                },
                'attendanceRecords as present_count' => function ($query) use ($batch) {
                    $query->where('status', 'present')
                        ->whereHas('class', function ($q) use ($batch) {
                            $q->where('batch_id', $batch->id);
                        });
                },
                'attendanceRecords as late_count' => function ($query) use ($batch) {
                    $query->where('status', 'late')
                        ->whereHas('class', function ($q) use ($batch) {
                            $q->where('batch_id', $batch->id);
                        });
                },
                'attendanceRecords as absent_count' => function ($query) use ($batch) {
                    $query->where('status', 'absent')
                        ->whereHas('class', function ($q) use ($batch) {
                            $q->where('batch_id', $batch->id);
                        });
                }
            ])
            ->get();

        return response()->json([
            'success' => true,
            'data' => $students
        ]);
    }

    /**
     * Get instructors in a batch.
     */
    public function getInstructors($id)
    {
        $user = auth('api')->user();
        $batch = Batch::findOrFail($id);

        // Check if user has access to this batch
        if ($user->role !== 'admin') {
            $hasAccess = $user->batches()->where('batches.id', $batch->id)->exists();
            
            if (!$hasAccess) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized.'
                ], 403);
            }
        }

        // Get instructors with class count
        $instructors = $batch->instructors()
            ->withCount([
                'instructorClasses as class_count' => function ($query) use ($batch) {
                    $query->where('batch_id', $batch->id);
                }
            ])
            ->get();

        return response()->json([
            'success' => true,
            'data' => $instructors
        ]);
    }
}