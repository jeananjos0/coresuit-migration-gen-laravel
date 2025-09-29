<?php

namespace CoreSuit\MigrationGen\Support;

class UseLineInserter
{
    public function insertUseLineSmart(string $contents, string $useLine): string
    {
        // 1) Se houver namespace, insere após o namespace e bloco de uses
        if (preg_match('/^namespace\s+[^;]+;\s*/m', $contents, $namespaceMatch, PREG_OFFSET_CAPTURE)) {
            $afterNamespacePos = $namespaceMatch[0][1] + strlen($namespaceMatch[0][0]);

            if (preg_match('/\G(?:(?:\R|\s)*)((?:use\s+[^;]+;\s*\R)*)/A', substr($contents, $afterNamespacePos), $useBlockMatch)) {
                $insertPos = $afterNamespacePos + strlen($useBlockMatch[1] ?? '');
                if (strpos($contents, $useLine) === false) {
                    $before = substr($contents, 0, $insertPos);
                    $after  = substr($contents, $insertPos);
                    return rtrim($before, "\r\n") . "\n{$useLine}\n\n" . ltrim($after, "\r\n");
                }
                return $contents;
            }
        }

        // 2) Sem namespace: insere após "<?php" e bloco de uses do topo
        if (preg_match('/^\s*<\?php\b/m', $contents, $phpMatch, PREG_OFFSET_CAPTURE)) {
            $phpPos = $phpMatch[0][1] + strlen($phpMatch[0][0]);

            if (preg_match('/\G(?:(?:\R|\s)*)((?:use\s+[^;]+;\s*\R)*)/A', substr($contents, $phpPos), $useBlockMatch)) {
                $insertPos = $phpPos + strlen($useBlockMatch[1] ?? '');
                if (strpos($contents, $useLine) === false) {
                    $before = substr($contents, 0, $insertPos);
                    $after  = substr($contents, $insertPos);
                    return rtrim($before, "\r\n") . "\n{$useLine}\n\n" . ltrim($after, "\r\n");
                }
                return $contents;
            }
        }

        // 3) Fallback: injeta logo após "<?php" (ou adiciona no topo)
        if (preg_match('/^\s*<\?php\b/m', $contents, $phpOnlyMatch, PREG_OFFSET_CAPTURE)) {
            $insertPos = $phpOnlyMatch[0][1] + strlen($phpOnlyMatch[0][0]);
            if (strpos($contents, $useLine) === false) {
                $before = substr($contents, 0, $insertPos);
                $after  = substr($contents, $insertPos);
                return rtrim($before, "\r\n") . "\n{$useLine}\n\n" . ltrim($after, "\r\n");
            }
            return $contents;
        }

        $header = "<?php\n{$useLine}\n\n";
        if (str_starts_with(ltrim($contents), '<?php')) {
            return $this->insertUseLineSmart($contents, $useLine);
        }
        return $header . $contents;
    }
}
