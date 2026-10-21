<?php
declare(strict_types=1);

namespace App\Plugin\ContentCenter\Service;

final class MarkdownRenderer
{
    public function render(string $markdown): string
    {
        $markdown = str_replace(["\r\n", "\r"], "\n", $markdown);
        $markdown = preg_replace('/^\xEF\xBB\xBF/', '', $markdown) ?? $markdown;
        $markdown = preg_replace('/\A---\n.*?\n---(?:\n|\z)/s', '', $markdown) ?? $markdown;

        $html = [];
        $paragraph = [];
        $quote = [];
        $list = null;
        $inFence = false;
        $fenceCharacter = '';
        $fenceLength = 0;
        $codeLanguage = '';
        $codeLines = [];

        $flushParagraph = function () use (&$paragraph, &$html): void {
            if (!$paragraph) {
                return;
            }
            $html[] = '<p>' . $this->inline(implode(' ', $paragraph)) . '</p>';
            $paragraph = [];
        };
        $flushQuote = function () use (&$quote, &$html): void {
            if (!$quote) {
                return;
            }
            $html[] = '<blockquote><p>' . $this->inline(implode(' ', $quote)) . '</p></blockquote>';
            $quote = [];
        };
        $flushList = function () use (&$list, &$html): void {
            if (!$list) {
                return;
            }
            $attributes = $list['tag'] === 'ol' && $list['start'] !== 1
                ? ' start="' . $list['start'] . '"'
                : '';
            $items = array_map(fn(string $item): string => '<li>' . $this->inline($item) . '</li>', $list['items']);
            $html[] = '<' . $list['tag'] . $attributes . '>' . implode('', $items) . '</' . $list['tag'] . '>';
            $list = null;
        };
        $flushCode = function () use (&$html, &$codeLines, &$codeLanguage): void {
            $class = $codeLanguage !== ''
                ? ' class="language-' . htmlspecialchars($codeLanguage, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"'
                : '';
            $html[] = '<pre><code' . $class . '>'
                . htmlspecialchars(implode("\n", $codeLines), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                . '</code></pre>';
            $codeLines = [];
            $codeLanguage = '';
        };

        foreach (explode("\n", $markdown) as $line) {
            if ($inFence) {
                $closingPattern = '#^\s*' . preg_quote($fenceCharacter, '#') . '{' . $fenceLength . ',}\s*$#';
                if (preg_match($closingPattern, $line)) {
                    $flushCode();
                    $inFence = false;
                    continue;
                }
                $codeLines[] = $line;
                continue;
            }

            if (preg_match('/^\s*(`{3,}|~{3,})\s*([a-zA-Z0-9_+-]*)\s*$/', $line, $match)) {
                $flushParagraph();
                $flushQuote();
                $flushList();
                $inFence = true;
                $fenceCharacter = $match[1][0];
                $fenceLength = strlen($match[1]);
                $codeLanguage = $match[2];
                continue;
            }

            $trimmed = trim($line);
            if ($trimmed === '') {
                $flushParagraph();
                $flushQuote();
                $flushList();
                continue;
            }

            if (preg_match('/^(#{1,6})\s+(.+)$/', $trimmed, $match)) {
                $flushParagraph();
                $flushQuote();
                $flushList();
                $level = strlen($match[1]);
                $html[] = '<h' . $level . '>' . $this->inline(trim($match[2], " \t#")) . '</h' . $level . '>';
                continue;
            }

            if (preg_match('/^(?:-{3,}|\*{3,}|_{3,})$/', $trimmed)) {
                $flushParagraph();
                $flushQuote();
                $flushList();
                $html[] = '<hr>';
                continue;
            }

            if (preg_match('/^>\s?(.*)$/', $trimmed, $match)) {
                $flushParagraph();
                $flushList();
                $quote[] = $match[1];
                continue;
            }
            $flushQuote();

            if (preg_match('/^[-+*]\s+(.+)$/', $trimmed, $match)) {
                $flushParagraph();
                if ($list && $list['tag'] !== 'ul') {
                    $flushList();
                }
                $list ??= ['tag' => 'ul', 'start' => 1, 'items' => []];
                $list['items'][] = $match[1];
                continue;
            }

            if (preg_match('/^(\d+)[.)]\s+(.+)$/', $trimmed, $match)) {
                $flushParagraph();
                if ($list && $list['tag'] !== 'ol') {
                    $flushList();
                }
                $list ??= ['tag' => 'ol', 'start' => (int)$match[1], 'items' => []];
                $list['items'][] = $match[2];
                continue;
            }

            $flushList();
            $paragraph[] = $trimmed;
        }

        if ($inFence) {
            $flushCode();
        }
        $flushParagraph();
        $flushQuote();
        $flushList();

        return implode("\n", $html);
    }

    private function inline(string $text): string
    {
        $tokens = [];
        $store = static function (string $html) use (&$tokens): string {
            $key = "\x1A" . count($tokens) . "\x1A";
            $tokens[$key] = $html;
            return $key;
        };

        $text = preg_replace_callback('/`([^`\n]+)`/', static function (array $match) use ($store): string {
            return $store('<code>' . htmlspecialchars($match[1], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code>');
        }, $text) ?? $text;

        $text = preg_replace_callback('/!\[([^\]]*)\]\(([^\s)]+)(?:\s+["\']([^"\']*)["\'])?\)/', function (array $match) use ($store): string {
            $url = $this->safeImageUrl($match[2]);
            if ($url === null) {
                return htmlspecialchars($match[1], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            }
            $title = isset($match[3]) && $match[3] !== ''
                ? ' title="' . htmlspecialchars($match[3], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"'
                : '';
            return $store('<img src="' . htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                . '" alt="' . htmlspecialchars($match[1], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                . '"' . $title . ' loading="lazy">');
        }, $text) ?? $text;

        $text = preg_replace_callback('/\[([^\]]+)\]\(([^\s)]+)(?:\s+["\']([^"\']*)["\'])?\)/', function (array $match) use ($store): string {
            $url = $this->safeLinkUrl($match[2]);
            if ($url === null) {
                return $this->formatText($match[1]);
            }
            $external = preg_match('#^https?://#i', $url) === 1;
            $title = isset($match[3]) && $match[3] !== ''
                ? ' title="' . htmlspecialchars($match[3], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"'
                : '';
            $target = $external ? ' target="_blank" rel="noopener noreferrer"' : '';
            return $store('<a href="' . htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                . '"' . $title . $target . '>' . $this->formatText($match[1]) . '</a>');
        }, $text) ?? $text;

        return strtr($this->formatText($text), $tokens);
    }

    private function formatText(string $text): string
    {
        $text = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $text = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $text) ?? $text;
        $text = preg_replace('/__(.+?)__/s', '<strong>$1</strong>', $text) ?? $text;
        $text = preg_replace('/~~(.+?)~~/s', '<del>$1</del>', $text) ?? $text;
        $text = preg_replace('/(?<!\*)\*([^*\n]+)\*(?!\*)/', '<em>$1</em>', $text) ?? $text;
        return preg_replace('/(?<!\w)_([^_\n]+)_(?!\w)/', '<em>$1</em>', $text) ?? $text;
    }

    private function safeLinkUrl(string $url): ?string
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($url === '' || str_starts_with($url, '//')) {
            return null;
        }
        if (str_starts_with($url, '/') || str_starts_with($url, '#') || str_starts_with($url, './') || str_starts_with($url, '../')) {
            return $url;
        }
        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
        return in_array($scheme, ['http', 'https', 'mailto', 'tel'], true) ? $url : null;
    }

    private function safeImageUrl(string $url): ?string
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($url === '' || str_starts_with($url, '//')) {
            return null;
        }
        if (str_starts_with($url, '/')) {
            return $url;
        }
        return strtolower((string)parse_url($url, PHP_URL_SCHEME)) === 'https' ? $url : null;
    }
}
