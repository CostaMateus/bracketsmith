<?php

namespace BracketSmith\Tests;

use PHPUnit\Framework\TestCase;
use BracketSmith\BracketSmith;

class BracketSmithTest extends TestCase
{
    private string $directory;
    private string $previousDirectory;

    protected function setUp(): void
    {
        $this->previousDirectory = getcwd();
        $this->directory = sys_get_temp_dir().'/bracketsmith-'.bin2hex(random_bytes(8));
        mkdir($this->directory);
        chdir($this->directory);
        ob_start();
    }

    protected function tearDown(): void
    {
        ob_end_clean();
        chdir($this->previousDirectory);
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->directory);
    }

    private function cli(array $arguments): array
    {
        $process = proc_open([PHP_BINARY, dirname(__DIR__).'/bin/bracketsmith', ...$arguments], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($process), $output, $error];
    }

    public function test_cli_returns_failure_for_a_missing_file(): void
    {
        [$exit, $output] = $this->cli(['missing.php']);
        $this->assertSame(1, $exit, $output);
    }

    public function test_include_configuration_processes_files(): void
    {
        file_put_contents('example.php', '<?php return [1,2];');
        file_put_contents('bracketsmith.json', '{"include":["example.php"]}');
        $this->assertTrue((new BracketSmith())->run());
        $this->assertSame('<?php return [ 1,2 ];', file_get_contents('example.php'));
    }

    public function test_exclusions_work_with_windows_directory_separators(): void
    {
        $method = (new \ReflectionClass(BracketSmith::class))->getMethod('shouldSkipFile');
        $this->assertTrue($method->invoke(new BracketSmith(), 'project\\vendor\\dependency\\file.php'));
    }

    public function test_directory_processing_propagates_file_failures(): void
    {
        file_put_contents('example.php', '<?php return [1];');
        $smith = new class extends BracketSmith {
            public function processFile(string $path): bool { return false; }
        };
        $this->assertFalse($smith->processDirectory('.'));
    }

    public function test_invalid_configuration_does_not_rewrite_default_directories(): void
    {
        mkdir('app');
        file_put_contents('app/example.php', '<?php return [1,2];');
        file_put_contents('bracketsmith.json', '{invalid');
        [$exit] = $this->cli([]);
        $this->assertSame('<?php return [1,2];', file_get_contents('app/example.php'));
        $this->assertSame(1, $exit);
    }

    public function test_configuration_rejects_non_string_paths(): void
    {
        file_put_contents('bracketsmith.json', '{"include":[42]}');
        $this->expectException(\InvalidArgumentException::class);
        new BracketSmith();
    }

    public function test_configuration_rejects_array_roots_and_object_path_lists(): void
    {
        mkdir('app');
        foreach (['[]', '["app/"]', '{"include":{"0":"app/"}}'] as $json) {
            file_put_contents('app/example.php', '<?php return [1,2];');
            file_put_contents('bracketsmith.json', $json);
            [$exit] = $this->cli([]);
            $this->assertSame(1, $exit, $json);
            $this->assertSame('<?php return [1,2];', file_get_contents('app/example.php'));
        }
    }

    public function test_empty_configuration_object_preserves_default_behavior(): void
    {
        mkdir('app');
        file_put_contents('app/example.php', '<?php return [1,2];');
        file_put_contents('bracketsmith.json', '{}');
        [$exit] = $this->cli([]);
        $this->assertSame(0, $exit);
        $this->assertSame('<?php return [ 1,2 ];', file_get_contents('app/example.php'));
    }

    public function test_unwritable_file_is_reported_without_claiming_a_successful_change(): void
    {
        file_put_contents('example.php', '<?php return [1,2];');
        chmod('example.php', 0444);
        try {
            if (is_writable('example.php')) {
                $this->markTestSkipped('The current account bypasses file write permissions.');
            }
            [$exit, $output] = $this->cli(['example.php']);
            $this->assertSame(1, $exit);
            $this->assertSame('<?php return [1,2];', file_get_contents('example.php'));
            $this->assertStringContainsString('0 of 1', $output);
        } finally {
            chmod('example.php', 0666);
        }
    }

    public function test_formatter_preserves_strings_interpolation_heredocs_and_is_idempotent(): void
    {
        $input = <<<'PHP'
<?php
#[Example([1,2])]
class Demo {
    public readonly array $items;
    public function value($key) { return $this->items[$key] ?? [1,2]; }
}
$message = "value: {$items[0]}";
$command = `echo [keep]`;
$literal = '[1,2]';
$pattern = '/[a-z,]/';
// [1,2]
$text = <<<TEXT
keep [1,2] {$items[0]}
TEXT;
$nowdoc = <<<'TEXT'
keep [1,2]
TEXT;
$values = match ($value) { 1 => [2,3], default => [] };
PHP;
        file_put_contents('example.php', $input);
        $this->assertTrue((new BracketSmith())->processFile('example.php'));
        $formatted = file_get_contents('example.php');
        $tokens = static fn(string $code) => array_values(array_filter(token_get_all($code, TOKEN_PARSE), static fn($token) => !is_array($token) || $token[0] !== T_WHITESPACE));
        // Compare token types/text without line positions, which whitespace may change.
        $semantic = static fn(array $items) => array_map(static fn($token) => is_array($token) ? [$token[0], $token[1]] : $token, $items);
        $this->assertSame($semantic($tokens($input)), $semantic($tokens($formatted)));
        $smith = new BracketSmith();
        $this->assertTrue($smith->processFile('example.php'));
        $this->assertSame(['processed' => 1, 'changed' => 0], $smith->getStats());
    }

    public function test_cli_dry_run_is_read_only_and_formatting_is_idempotent(): void
    {
        file_put_contents('example.php', '<?php return [1,2];');
        [$exit] = $this->cli(['--dry-run', 'example.php']);
        $this->assertSame(0, $exit);
        $this->assertSame('<?php return [1,2];', file_get_contents('example.php'));
        [$exit] = $this->cli(['example.php']);
        $this->assertSame(0, $exit);
        $this->assertSame('<?php return [ 1,2 ];', file_get_contents('example.php'));
        [$exit, $output] = $this->cli(['example.php']);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('0 of 1', $output);
    }

    public function test_it_can_process_a_simple_string()
    {
        $bs = new BracketSmith( true );

        $result = ( new \ReflectionClass( $bs ) )
            ->getMethod( "addSpacesToArrays" )
            ->invoke( $bs, "<?php return [1,2,3];" );

        $this->assertStringContainsString( "[ 1,2,3 ]", $result );
    }

    public function test_it_formats_brackets_without_touching_comments_or_strings()
    {
        $bs = new BracketSmith( true );

        $result = ( new \ReflectionClass( $bs ) )
            ->getMethod( "addSpacesToArrays" )
            ->invoke( $bs, <<<'PHP'
                <?php
                $array = [1, 'two', $three];
                $value = $array[0];
                $empty = [];
                $string = "[do not touch]";
                // [do not touch]
            PHP );

        $this->assertStringContainsString( '$array = [ 1, \'two\', $three ];', $result );
        $this->assertStringContainsString( '$value = $array[ 0 ];', $result );
        $this->assertStringContainsString( '$empty = [];', $result );
        $this->assertStringContainsString( '$string = "[do not touch]";', $result );
        $this->assertStringContainsString( '// [do not touch]', $result );
    }
}
