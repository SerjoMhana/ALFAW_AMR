<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Simple key/value store for admin-editable text that belongs to the school
 * rather than to any one student — currently the report card letter.
 */
class SchoolSetting extends Model
{
    public const SEMESTER_REPORT_MESSAGE = 'semester_report_message';

    public const DEFAULT_SEMESTER_REPORT_MESSAGE = <<<'TEXT'
        Dear families of Vision International School,
        We would like to congratulate all our students on successfully completing the term. Our students and staff have all worked hard towards making the most of exam week. We hope that everyone will be able to reap the rewards of their efforts. The VIS report cards will give students a sense of where they are now and where they would like to be. VIS will do our best, and strive to provide the environment necessary for our students to achieve their goals.
        TEXT;

    protected $fillable = ['key', 'value'];

    public static function get(string $key, ?string $default = null): ?string
    {
        $value = static::query()->where('key', $key)->value('value');

        return $value !== null ? $value : $default;
    }

    public static function set(string $key, ?string $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
    }

    public static function semesterReportMessage(): string
    {
        return (string) static::get(self::SEMESTER_REPORT_MESSAGE, self::DEFAULT_SEMESTER_REPORT_MESSAGE);
    }
}
