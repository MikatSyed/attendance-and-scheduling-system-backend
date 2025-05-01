<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Batch;
use App\Models\User;
use Illuminate\Http\Request;

class BatchController extends Controller
{
    public function index()
    {
        return Batch::with('students', 'creator')->get();
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'student_ids' => 'array',
            'student_ids.*' => 'exists:users,id'
        ]);

        $batch = Batch::create([
            'name' => $validated['name'],
            'created_by' => auth()->id(),
        ]);

        if (!empty($validated['student_ids'])) {
            $batch->students()->sync($validated['student_ids']);
        }

        return response()->json(['message' => 'Batch created', 'batch' => $batch->load('students')]);
    }

    public function show(Batch $batch)
    {
        return $batch->load('students', 'creator');
    }

    public function update(Request $request, Batch $batch)
    {
        $this->authorize('update', $batch); // Optional

        $validated = $request->validate([
            'name' => 'string|max:255',
            'student_ids' => 'array',
            'student_ids.*' => 'exists:users,id'
        ]);

        $batch->update($request->only('name'));

        if (isset($validated['student_ids'])) {
            $batch->students()->sync($validated['student_ids']);
        }

        return response()->json(['message' => 'Batch updated', 'batch' => $batch->load('students')]);
    }

    public function destroy(Batch $batch)
    {
        $batch->delete();
        return response()->json(['message' => 'Batch deleted']);
    }
}
