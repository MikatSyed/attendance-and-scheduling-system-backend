<?php

namespace App\Http\Controllers;

use App\Models\Batch;
use App\Models\ClassSchedule;
use App\Models\User;
use App\Jobs\NotifyClassScheduled;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;

class ClassScheduleController extends Controller
{
    /**
     * Display a listing of the classes.
     */
    public function index(Request $request)
    {
        $user = auth('api')->user();
        $query = ClassSchedule::with(['batch', 'instructor']);

        // Filter by batch if provided
        if ($request->has('batch_id')) {
            $query->where('batch_id', $request->batch_id);
        }

        // Filter by date range if provided
        if ($request->has('start_date') && $request->has('end_date')) {
            $query->whereBetween('start_time', [
                Carbon::parse($request->start_date)->startOfDay(),
                Carbon::parse($request->end_date)->endOfDay()
            ]);
        }

        // If student, only show classes from their batches
        if ($user->isStudent()) {
            $batchIds = $user->batches()->pluck('batches.id');
            $query->whereIn('batch_id', $batchIds);
        }
        // If instructor, only show their classes
        elseif ($user->isInstructor()) {
            $query->where('instructor_id', $user->id);
        }

        // Order by start time
        $query->orderBy('start_time', 'desc');

        // Paginate results
        $classes = $query->paginate(15);

        return response()->json([
            'success' => true,
            'data' => $classes
        ]);
    }

    /**
     * Get upcoming classes.
     */
    public function upcoming(Request $request)
    {
        $user = auth('api')->user();
        $now = Carbon::now();
        $query = ClassSchedule::with(['batch', 'instructor'])
            ->where('start_time', '>=', $now);

        // Filter by batch if provided
        if ($request->has('batch_id')) {
            $query->where('batch_id', $request->batch_id);
        }

        // If student, only show classes from their batches
        if ($user->isStudent()) {
            $batchIds = $user->batches()->pluck('batches.id');
            $query->whereIn('batch_id', $batchIds);
        }
        // If instructor, only show their classes
        elseif ($user->isInstructor()) {
            $query->where('instructor_id', $user->id);
        }

        // Order by start time
        $query->orderBy('start_time', 'asc');

        // Paginate results
        $classes = $query->paginate(15);

        return response()->json([
            'success' => true,
            'data' => $classes
        ]);
    }

    /**
     * Store a newly created class in storage.
     */
    public function store(Request $request)
    {
        $user = auth('api')->user();

        // Only instructors can create classes
        if (!$user->isInstructor()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Only instructors can create classes.'
            ], 403);
        }

        // Validate request
        $validator = Validator::make($request->all(), [
            'batch_id' => 'required|exists:batches,id',
            'topic' => 'required|string|max:255',
            'start_time' => 'required|date|after:now',
            'duration' => 'required|integer|min:15|max:240',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        // Check if instructor is assigned to the batch
        $batch = Batch::find($request->batch_id);
        $isAssigned = $batch->instructors()->where('users.id', $user->id)->exists();

        if (!$isAssigned) {
            return response()->json([
                'success' => false,
                'message' => 'You are not assigned to this batch.'
            ], 403);
        }

        // Create class
        $class = ClassSchedule::create([
            'batch_id' => $request->batch_id,
            'instructor_id' => $user->id,
            'topic' => $request->topic,
            'start_time' => $request->start_time,
            'duration' => $request->duration,
        ]);

        // Dispatch job to notify students
        NotifyClassScheduled::dispatch($class);

        return response()->json([
            'success' => true,
            'message' => 'Class scheduled successfully.',
            'data' => $class
        ], 201);
    }

    /**
     * Display the specified class.
     */
    public function show($id)
    {
        $user = auth('api')->user();
        $class = ClassSchedule::with(['batch', 'instructor'])->findOrFail($id);

        // Check if user has access to this class
        if ($user->isStudent()) {
            $hasAccess = $user->batches()->where('batches.id', $class->batch_id)->exists();
            
            if (!$hasAccess) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized.'
                ], 403);
            }
        } elseif ($user->isInstructor() && $class->instructor_id !== $user->id) {
            $hasAccess = $user->batches()->where('batches.id', $class->batch_id)->exists();
            
            if (!$hasAccess) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized.'
                ], 403);
            }
        }

        return response()->json([
            'success' => true,
            'data' => $class
        ]);
    }

    /**
     * Update the specified class in storage.
     */
    public function update(Request $request, $id)
    {
        $user = auth('api')->user();
        $class = ClassSchedule::findOrFail($id);

        // Only the instructor who created the class can update it
        if ($user->isInstructor() && $class->instructor_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Only the instructor who created this class can update it.'
            ], 403);
        }

        // Validate request
        $validator = Validator::make($request->all(), [
            'topic' => 'sometimes|required|string|max:255',
            'start_time' => 'sometimes|required|date|after:now',
            'duration' => 'sometimes|required|integer|min:15|max:240',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        // Update class
        $class->update($request->only(['topic', 'start_time', 'duration']));

        return response()->json([
            'success' => true,
            'message' => 'Class updated successfully.',
            'data' => $class
        ]);
    }

    /**
     * Remove the specified class from storage.
     */
    public function destroy($id)
    {
        $user = auth('api')->user();
        $class = ClassSchedule::findOrFail($id);

        // Only the instructor who created the class can delete it
        if ($user->isInstructor() && $class->instructor_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Only the instructor who created this class can delete it.'
            ], 403);
        }

        // Delete class
        $class->delete();

        return response()->json([
            'success' => true,
            'message' => 'Class deleted successfully.'
        ]);
    }
}