<?php

namespace Database\Seeders;

use App\Models\Batch;
use App\Models\User;
use Illuminate\Database\Seeder;

class BatchSeeder extends Seeder
{
    public function run(): void
    {
        try {
            $students = User::where('role', 'student')->pluck('id');

            $count = min($students->count(), 80); // prevent over-random

            $studentIds = $students->random(rand(30, $count))->toArray();

            // Create 10 batches
            for ($i = 1; $i <= 10; $i++) {
                $instructor = $instructors->random();

                $batch = Batch::create([
                    'name' => 'Batch ' . $i,
                    'created_by' => $instructor->id,
                ]);

                // Assign 30–80 students randomly
                $studentIds = $students->random(rand(30, 80))->toArray();
                $batch->students()->sync($studentIds);
            }
        } catch (\Throwable $e) {
            logger()->error('BatchSeeder failed: ' . $e->getMessage());
        }
    }
}
