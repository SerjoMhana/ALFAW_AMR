<?php

namespace App\Services;

class GradingStructureImportService
{
    private const ROLLUP_CATEGORY_NAMES = [
        'quizzes total' => 'Quizzes',
        'tests total' => 'Tests',
        'assign.s total' => 'Assignments',
        'assignments total' => 'Assignments',
    ];

    private const FRIENDLY_NAMES = [
        'quar. final' => 'Quarter Final',
        'proj/res. p' => 'Project / Research Paper',
    ];

    public function __construct(private readonly SimpleXlsxReader $reader) {}

    /**
     * @return array<int, array{
     *     sheet_name: string,
     *     tier_name: string,
     *     min_grade: int|null,
     *     max_grade: int|null,
     *     categories: array<int, array{name: string, weight_percentage: float, items: array<int, array{name: string, max_score: float, is_total_field: bool}>}>,
     *     total_weight: float,
     * }>
     */
    public function preview(string $path): array
    {
        $sheetNames = $this->reader->sheetNames($path);

        return collect($sheetNames)
            ->map(fn (string $sheetName, int $index) => $this->parseSheet($path, $index, $sheetName))
            ->filter()
            ->values()
            ->all();
    }

    private function parseSheet(string $path, int $sheetIndex, string $sheetName): ?array
    {
        $rows = $this->reader->rows($path, $sheetIndex);

        $headerRowIndex = null;
        foreach ($rows as $index => $row) {
            if (in_array('Student Name', $row, true)) {
                $headerRowIndex = $index;
                break;
            }
        }

        if ($headerRowIndex === null) {
            return null;
        }

        $headerRow = $rows[$headerRowIndex] ?? [];
        $weightsRow = $rows[$headerRowIndex + 1] ?? [];
        $maxScoreRow = $rows[$headerRowIndex + 2] ?? [];

        $lastColumn = max(array_keys($headerRow));
        $categories = [];
        $buffer = [];

        for ($col = 2; $col <= $lastColumn; $col++) {
            $header = trim((string) ($headerRow[$col] ?? ''));
            if ($header === '') {
                continue;
            }

            if (strcasecmp($header, 'Total') === 0) {
                break;
            }

            $maxScore = isset($maxScoreRow[$col]) && $maxScoreRow[$col] !== null
                ? (float) $maxScoreRow[$col]
                : 0.0;
            $weightRaw = $weightsRow[$col] ?? null;
            $isRollup = str_contains(strtolower($header), 'total');
            $itemName = self::FRIENDLY_NAMES[strtolower($header)] ?? $header;

            $buffer[] = [
                'name' => $itemName,
                'max_score' => $maxScore,
                'is_total_field' => $isRollup,
            ];

            if ($weightRaw === null || $weightRaw === '') {
                continue;
            }

            $weightPercentage = round(((float) $weightRaw) * 100, 2);
            $categoryName = $isRollup
                ? (self::ROLLUP_CATEGORY_NAMES[strtolower($header)] ?? trim(preg_replace('/\s*total$/i', '', $header)))
                : $itemName;

            $categories[] = [
                'name' => $categoryName,
                'weight_percentage' => $weightPercentage,
                'items' => $buffer,
            ];

            $buffer = [];
        }

        preg_match('/G\s*(\d+)\s*-\s*(\d+)/i', $sheetName, $matches);

        return [
            'sheet_name' => $sheetName,
            'tier_name' => $sheetName,
            'min_grade' => isset($matches[1]) ? (int) $matches[1] : null,
            'max_grade' => isset($matches[2]) ? (int) $matches[2] : null,
            'categories' => $categories,
            'total_weight' => round(array_sum(array_column($categories, 'weight_percentage')), 2),
        ];
    }
}
