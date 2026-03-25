<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Namespace_;

use Qualimetrix\Core\Namespace_\NamespaceDetectorInterface;
use SplFileInfo;

final class TokenizerNamespaceDetector implements NamespaceDetectorInterface
{
    private const READ_BYTES = 4096;

    public function detect(SplFileInfo $file): string
    {
        $path = $file->getPathname();

        if (!is_file($path) || !is_readable($path)) {
            return '';
        }

        $handle = fopen($path, 'r');
        if ($handle === false) {
            return '';
        }

        $content = fread($handle, self::READ_BYTES);
        fclose($handle);

        if ($content === false || $content === '') {
            return '';
        }

        return $this->extractNamespace($content);
    }

    private function extractNamespace(string $content): string
    {
        $tokens = @token_get_all($content);

        $namespace = '';
        $collectingNamespace = false;

        foreach ($tokens as $token) {
            if (\is_array($token)) {
                $tokenType = $token[0];
                $tokenValue = $token[1];

                if ($tokenType === \T_NAMESPACE) {
                    $collectingNamespace = true;
                    $namespace = '';

                    continue;
                }

                if ($collectingNamespace) {
                    if ($tokenType === \T_NAME_QUALIFIED || $tokenType === \T_STRING) {
                        $namespace .= $tokenValue;
                    } elseif ($tokenType === \T_NS_SEPARATOR) {
                        $namespace .= '\\';
                    } elseif ($tokenType !== \T_WHITESPACE) {
                        // Non-whitespace, non-namespace token — stop collecting
                        break;
                    }
                }
            } else {
                // Single character tokens
                if ($collectingNamespace && ($token === ';' || $token === '{')) {
                    break;
                }
            }
        }

        return trim($namespace);
    }
}
