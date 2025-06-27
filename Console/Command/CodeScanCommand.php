<?php
/**
 * Copyright © Rodrigo Cardoso. All rights reserved.
 */
declare(strict_types=1);

namespace DevHelper\CodeScanner\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Class CodeScanCommand
 *
 * Scans custom modules for common Magento 2 bad practices.
 */
class CodeScanCommand extends Command
{
    /**
     * Configure the CLI command.
     *
     * @return void
     */
    protected function configure(): void
    {
        $this->setName('devhelper:codescan')
            ->setDescription('Varre módulos personalizados em busca de más práticas de código.');
    }

    /**
     * Execute the CLI command.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('DevHelper Code Scanner');
        $io->note('Scanning app/code for bad practices...');

        $baseDir = BP . '/app/code';
        $issues = [];

        $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($baseDir));
        foreach ($rii as $file) {
            if ($file->isDir()) continue;
            $filePath = $file->getPathname();
            $relPath = str_replace($baseDir . '/', '', $filePath);
            $module = explode('/', $relPath)[0] ?? '';

            $issues = array_merge($issues, $this->checkObjectManager($filePath, $relPath, $module));
            $issues = array_merge($issues, $this->checkDirectNew($filePath, $relPath, $module));
            $issues = array_merge($issues, $this->checkBadBlockDeclaration($filePath, $relPath, $module));
            $issues = array_merge($issues, $this->checkGenericObserver($filePath, $relPath, $module));
            $issues = array_merge($issues, $this->checkPluginBeforeNoReturn($filePath, $relPath, $module));
            $issues = array_merge($issues, $this->checkPluginAfterAroundNoReturn($filePath, $relPath, $module));
            $issues = array_merge($issues, $this->checkDangerousPhpFunctions($filePath, $relPath, $module));
            $issues = array_merge($issues, $this->checkDebugFunctions($filePath, $relPath, $module));
        }

        if (empty($issues)) {
            $io->success('No bad practices found!');
        } else {
            $io->warning('Possible bad practices found:');
            $io->table(['Type', 'Module', 'File', 'Line'], $issues);
        }
        return Command::SUCCESS;
    }

    /**
     * Detect direct usage of ObjectManager
     *
     * @param string $filePath
     * @param string $relPath
     * @param string $module
     * @return array<array{type:string,module:string,file:string,line:string}>
     */
    private function checkObjectManager(string $filePath, string $relPath, string $module): array
    {
        $results = [];
        if (preg_match_all('/ObjectManager::getInstance\(/', file_get_contents($filePath), $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $match) {
                $line = (string)self::getLineNumber($filePath, (int)$match[1]);
                $results[] = [
                    'type' => 'Direct ObjectManager usage',
                    'module' => $module,
                    'file' => $relPath,
                    'line' => $line
                ];
            }
        }
        return $results;
    }

    /**
     * Detect direct instantiation with new (except in Test/)
     *
     * @param string $filePath
     * @param string $relPath
     * @param string $module
     * @return array<array{type:string,module:string,file:string,line:string}>
     */
    private function checkDirectNew(string $filePath, string $relPath, string $module): array
    {
        $results = [];
        if (strpos($filePath, '/Test/') === false && preg_match_all('/new\s+\\?[A-Za-z0-9_]+/', file_get_contents($filePath), $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $match) {
                $line = (string)self::getLineNumber($filePath, (int)$match[1]);
                $results[] = [
                    'type' => 'Direct instantiation with new',
                    'module' => $module,
                    'file' => $relPath,
                    'line' => $line
                ];
            }
        }
        return $results;
    }

    /**
     * Detect blocks in XML without class/template
     *
     * @param string $filePath
     * @param string $relPath
     * @param string $module
     * @return array<array{type:string,module:string,file:string,line:string}>
     */
    private function checkBadBlockDeclaration(string $filePath, string $relPath, string $module): array
    {
        $results = [];
        if (preg_match('/\.xml$/', $filePath)) {
            $xml = @simplexml_load_file($filePath);
            if ($xml) {
                foreach ($xml->xpath('//block') as $block) {
                    $attrs = $block->attributes();
                    if (empty($attrs['class']) && empty($attrs['template'])) {
                        $results[] = [
                            'type' => 'Block without class/template',
                            'module' => $module,
                            'file' => $relPath,
                            'line' => ''
                        ];
                    }
                }
            }
        }
        return $results;
    }

    /**
     * Detect observers listening to generic events
     *
     * @param string $filePath
     * @param string $relPath
     * @param string $module
     * @return array<array{type:string,module:string,file:string,line:string}>
     */
    private function checkGenericObserver(string $filePath, string $relPath, string $module): array
    {
        $results = [];
        if (preg_match('/\.xml$/', $filePath)) {
            $xml = @simplexml_load_file($filePath);
            if ($xml) {
                foreach ($xml->xpath('//observer') as $observer) {
                    $attrs = $observer->attributes();
                    if (!empty($attrs['name']) && isset($attrs['name']) && preg_match('/controller_action|all|.*_all/i', (string)$attrs['name'])) {
                        $results[] = [
                            'type' => 'Observer listening to generic event',
                            'module' => $module,
                            'file' => $relPath,
                            'line' => ''
                        ];
                    }
                }
            }
        }
        return $results;
    }

    /**
     * Detect plugin before methods without return
     *
     * @param string $filePath
     * @param string $relPath
     * @param string $module
     * @return array<array{type:string,module:string,file:string,line:string}>
     */
    private function checkPluginBeforeNoReturn(string $filePath, string $relPath, string $module): array
    {
        $results = [];
        if (preg_match('/Plugin\//', $filePath) && preg_match_all('/function before[A-Z][A-Za-z0-9_]*\((.*?)\)\s*\{([^}]*)\}/s', file_get_contents($filePath), $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                if (strpos($match[2], 'return') === false) {
                    $line = (string)self::getLineNumber($filePath, (int)strpos(file_get_contents($filePath), $match[0]));
                    $results[] = [
                        'type' => 'Plugin before without return',
                        'module' => $module,
                        'file' => $relPath,
                        'line' => $line
                    ];
                }
            }
        }
        return $results;
    }

    /**
     * Detect plugin after/around methods without return
     *
     * @param string $filePath
     * @param string $relPath
     * @param string $module
     * @return array<array{type:string,module:string,file:string,line:string}>
     */
    private function checkPluginAfterAroundNoReturn(string $filePath, string $relPath, string $module): array
    {
        $results = [];
        if (preg_match('/Plugin\//', $filePath)) {
            $content = file_get_contents($filePath);
            // after
            if (preg_match_all('/function after[A-Z][A-Za-z0-9_]*\((.*?)\)\s*\{([^}]*)\}/s', $content, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    if (strpos($match[2], 'return') === false) {
                        $line = (string)self::getLineNumber($filePath, (int)strpos($content, $match[0]));
                        $results[] = [
                            'type' => 'Plugin after without return',
                            'module' => $module,
                            'file' => $relPath,
                            'line' => $line
                        ];
                    }
                }
            }
            // around
            if (preg_match_all('/function around[A-Z][A-Za-z0-9_]*\((.*?)\)\s*\{([^}]*)\}/s', $content, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    if (strpos($match[2], 'return') === false) {
                        $line = (string)self::getLineNumber($filePath, (int)strpos($content, $match[0]));
                        $results[] = [
                            'type' => 'Plugin around without return',
                            'module' => $module,
                            'file' => $relPath,
                            'line' => $line
                        ];
                    }
                }
            }
        }
        return $results;
    }

    /**
     * Detect dangerous PHP functions (eval, exec, shell_exec, system, passthru, proc_open, popen)
     *
     * @param string $filePath
     * @param string $relPath
     * @param string $module
     * @return array<array{type:string,module:string,file:string,line:string}>
     */
    private function checkDangerousPhpFunctions(string $filePath, string $relPath, string $module): array
    {
        $results = [];
        $dangerous = ['eval', 'exec', 'shell_exec', 'system', 'passthru', 'proc_open', 'popen'];
        $content = file_get_contents($filePath);
        foreach ($dangerous as $fn) {
            if (preg_match_all('/\b' . $fn . '\s*\(/', $content, $matches, PREG_OFFSET_CAPTURE)) {
                foreach ($matches[0] as $match) {
                    $line = (string)self::getLineNumber($filePath, (int)$match[1]);
                    $results[] = [
                        'type' => 'Dangerous PHP function: ' . $fn,
                        'module' => $module,
                        'file' => $relPath,
                        'line' => $line
                    ];
                }
            }
        }
        return $results;
    }

    /**
     * Detect debug functions (var_dump, print_r, die, exit)
     *
     * @param string $filePath
     * @param string $relPath
     * @param string $module
     * @return array<array{type:string,module:string,file:string,line:string}>
     */
    private function checkDebugFunctions(string $filePath, string $relPath, string $module): array
    {
        $results = [];
        $debug = ['var_dump', 'print_r', 'die', 'exit'];
        $content = file_get_contents($filePath);
        foreach ($debug as $fn) {
            if (preg_match_all('/\b' . $fn . '\s*\(/', $content, $matches, PREG_OFFSET_CAPTURE)) {
                foreach ($matches[0] as $match) {
                    $line = (string)self::getLineNumber($filePath, (int)$match[1]);
                    $results[] = [
                        'type' => 'Debug function: ' . $fn,
                        'module' => $module,
                        'file' => $relPath,
                        'line' => $line
                    ];
                }
            }
        }
        return $results;
    }

    /**
     * Get line number from file offset
     *
     * @param string $filePath
     * @param int $offset
     * @return int
     */
    private static function getLineNumber(string $filePath, int $offset): int
    {
        $content = file_get_contents($filePath);
        $before = substr($content, 0, $offset);
        return substr_count($before, "\n") + 1;
    }
} 
