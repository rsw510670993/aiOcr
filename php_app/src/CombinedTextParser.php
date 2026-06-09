<?php
declare(strict_types=1);

namespace App;

use RuntimeException;

final class CombinedTextParser
{
    /** @return list<array{page:int,text:string}> */
    public function parseFile(string $path): array
    {
        if (!is_file($path)) {
            throw new RuntimeException('合并文本不存在：' . $path);
        }
        return $this->parseString((string) file_get_contents($path));
    }

    /** @return list<array{page:int,text:string}> */
    public function parseString(string $content): array
    {
        preg_match_all('/^P(\d+)\s*$/m', $content, $matches, PREG_OFFSET_CAPTURE);
        if (empty($matches[0])) {
            throw new RuntimeException('文本中缺少 P{页码} 标记。');
        }

        $pages = [];
        $count = count($matches[0]);
        for ($index = 0; $index < $count; $index++) {
            $page = (int) $matches[1][$index][0];
            $start = $matches[0][$index][1] + strlen($matches[0][$index][0]);
            $end = $index + 1 < $count ? $matches[0][$index + 1][1] : strlen($content);
            $text = trim(substr($content, $start, $end - $start));
            $pages[] = ['page' => $page, 'text' => $text];
        }

        return $pages;
    }

    /** @param list<array{page:int,text:string}> $pages */
    public function writeFile(string $path, array $pages): void
    {
        $directory = dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }
        file_put_contents($path, $this->format($pages));
    }

    /** @param list<array{page:int,text:string}> $pages */
    public function format(array $pages): string
    {
        $blocks = [];
        foreach ($pages as $page) {
            $blocks[] = 'P' . $page['page'] . PHP_EOL . trim($page['text']);
        }
        return implode(PHP_EOL . PHP_EOL, $blocks) . PHP_EOL;
    }

    /** @param list<array{page:int,text:string}> $pages */
    /** @return list<int> */
    public function pageNumbers(array $pages): array
    {
        return array_values(array_map(static fn (array $page): int => (int) $page['page'], $pages));
    }
}
