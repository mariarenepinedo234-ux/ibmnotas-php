<?php
declare(strict_types=1);

return [
    'users' => ['id', 'ci', 'name', 'role', 'password', 'must_change', 'blocked'],
    'careers' => ['id', 'name'],
    'career_names_by_year' => ['career_id', 'effective_year', 'name'],
    'shifts' => ['id', 'name'],
    'years' => ['id', 'name'],
    'subjects' => ['id', 'name', 'career_id', 'level', 'code', 'active', 'catalog_order'],
    'modules' => ['id', 'name', 'year_id', 'start_date', 'end_date', 'admission_year_id'],
    'students' => ['user_id', 'career_id', 'shift_id', 'admission_year_id', 'level', 'name_pending'],
    'subject_series' => ['id', 'subject_id', 'shift_id', 'part_count', 'completed'],
    'offerings' => ['id', 'subject_id', 'module_id', 'shift_id', 'teacher_id', 'unlocked_until', 'series_id', 'part_number', 'part_recovery'],
    'enrollments' => ['id', 'student_id', 'offering_id', 'grade', 'updated_at', 'saber', 'hacer', 'ser', 'is_retake'],
    'audit' => ['id', 'actor_id', 'action', 'detail', 'created_at'],
    'teacher_directory' => ['id', 'name', 'name_key', 'user_id'],
    'academic_groups' => ['id', 'subject_id', 'year_id', 'admission_year_id', 'shift_id', 'offering_id'],
    'academic_group_students' => ['group_id', 'student_id'],
    'study_plans' => ['id', 'title', 'career_id', 'level', 'original_name', 'content', 'size_bytes', 'uploaded_by', 'created_at'],
];
