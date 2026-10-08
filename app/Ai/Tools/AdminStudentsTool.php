<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Models\Student;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Throwable;

final readonly class AdminStudentsTool implements Tool
{
    public function description(): string
    {
        return 'Search or list students, enrollment status, academic standing, programs, and attendance.';
    }

    public function handle(Request $request): string
    {
        try {
            $query = trim((string) ($request['query'] ?? ''));
            $limit = min(20, max(1, (int) ($request['limit'] ?? 10)));

            $builder = Student::query()->with('user');

            if ($query !== '') {
                $builder->where(function ($q) use ($query): void {
                    $q->where('student_number', 'like', "%{$query}%")
                        ->orWhere('program', 'like', "%{$query}%")
                        ->orWhereHas('user', function ($uq) use ($query): void {
                            $uq->where('name', 'like', "%{$query}%")
                                ->orWhere('email', 'like', "%{$query}%");
                        });
                });
            }

            $total = Student::count();
            $students = $builder->latest('id')->limit($limit)->get()->map(fn (Student $student): array => [
                'id' => $student->id,
                'student_number' => $student->student_number,
                'name' => $student->user?->name ?? 'Unknown',
                'email' => $student->user?->email ?? 'Unknown',
                'program' => $student->program,
                'year_level' => $student->year_level,
                'enrollment_status' => $student->enrollment_status,
                'academic_status' => $student->academic_status,
                'attendance_rate' => $student->attendance_rate ? $student->attendance_rate.'%' : 'N/A',
                'payout_address' => $student->payout_address,
            ])->all();

            return json_encode([
                'total_students' => $total,
                'returned_count' => count($students),
                'students' => $students,
            ], JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            report($e);

            return 'Error retrieving student records: '.$e->getMessage();
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('Optional search term matching student name, email, number or program'),
            'limit' => $schema->integer()->description('Number of records to return (1-20, default 10)'),
        ];
    }
}
