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

    private function cli(array $arguments, array $phpOptions = []): array
    {
        $process = proc_open([PHP_BINARY, ...$phpOptions, dirname(__DIR__).'/bin/bracketsmith', ...$arguments], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($process), $output, $error];
    }

    public function test_unknown_options_never_process_default_files(): void
    {
        mkdir('app');
        file_put_contents('app/example.php', '<?php return [1,2];');
        foreach ([['--dryrun'], ['--unknown'], ['--dry-run=true'], ['--help', '--unknown']] as $arguments) {
            [$exit] = $this->cli($arguments);
            $this->assertSame(1, $exit);
            $this->assertSame('<?php return [1,2];', file_get_contents('app/example.php'));
        }
    }

    public function test_end_of_options_keeps_dash_prefixed_file_names(): void
    {
        mkdir('app');
        file_put_contents('app/example.php', '<?php return [1,2];');
        file_put_contents('--file.php', '<?php return [3,4];');
        [$exit] = $this->cli(['--', '--file.php']);
        $this->assertSame(0, $exit);
        $this->assertSame('<?php return [ 3,4 ];', file_get_contents('--file.php'));
        $this->assertSame('<?php return [1,2];', file_get_contents('app/example.php'));
    }

    public function test_dry_run_option_after_a_path_is_still_read_only(): void
    {
        file_put_contents('example.php', '<?php return [1,2];');
        [$exit] = $this->cli(['example.php', '--dry-run']);
        $this->assertSame(0, $exit);
        $this->assertSame('<?php return [1,2];', file_get_contents('example.php'));
    }

    public function test_unknown_configuration_keys_never_process_default_files(): void
    {
        mkdir('app');
        file_put_contents('app/example.php', '<?php return [1,2];');
        file_put_contents('bracketsmith.json', '{"includes":["safe/"]}');
        [$exit] = $this->cli([]);
        $this->assertSame(1, $exit);
        $this->assertSame('<?php return [1,2];', file_get_contents('app/example.php'));
    }

    public function test_missing_custom_targets_fail_but_missing_defaults_are_optional(): void
    {
        [$exit] = $this->cli([]);
        $this->assertSame(0, $exit);
        foreach (['missing.php', 'missing-directory/'] as $path) {
            file_put_contents('bracketsmith.json', json_encode(['include' => [$path]]));
            [$exit] = $this->cli([]);
            $this->assertSame(1, $exit, $path);
        }
        unlink('bracketsmith.json');
        $this->assertFalse((new BracketSmith())->setInclude(['missing.php'])->run());
        $this->assertFalse((new BracketSmith())->processDirectory('missing-directory/'));
    }

    public function test_partial_write_leaves_original_intact_and_removes_temporary_file(): void
    {
        file_put_contents('example.php', '<?php return [1,2];');
        file_put_contents('inject.php', <<<'PHP'
<?php
namespace BracketSmith;
function file_put_contents($path, $data, $flags = 0) {
    return \file_put_contents($path, substr($data, 0, 10), $flags);
}
PHP);
        [$exit] = $this->cli(['example.php'], ['-d', 'auto_prepend_file='.$this->directory.'/inject.php']);
        $this->assertSame(1, $exit);
        $this->assertSame('<?php return [1,2];', file_get_contents('example.php'));
        $this->assertSame([], glob($this->directory.'/bsm*'));
    }

    public function test_failed_replacement_leaves_original_intact_and_removes_temporary_file(): void
    {
        file_put_contents('example.php', '<?php return [1,2];');
        file_put_contents('inject.php', <<<'PHP'
<?php
namespace BracketSmith;
function rename($from, $to) { return false; }
PHP);
        [$exit] = $this->cli(['example.php'], ['-d', 'auto_prepend_file='.$this->directory.'/inject.php']);
        $this->assertSame(1, $exit);
        $this->assertSame('<?php return [1,2];', file_get_contents('example.php'));
        $this->assertSame([], glob($this->directory.'/bsm*'));
    }

    public function test_concurrent_edit_detected_before_replacement_is_preserved(): void
    {
        file_put_contents('example.php', '<?php return [1,2];');
        file_put_contents('inject.php', <<<'PHP'
<?php
namespace BracketSmith;
function file_put_contents($path, $data, $flags = 0) {
    $result = \file_put_contents($path, $data, $flags);
    if (str_starts_with(basename($path), 'bsm')) {
        \file_put_contents(dirname($path).'/example.php', '<?php return [9];');
    }
    return $result;
}
PHP);
        [$exit] = $this->cli(['example.php'], ['-d', 'auto_prepend_file='.$this->directory.'/inject.php']);
        $this->assertSame(1, $exit);
        $this->assertSame('<?php return [9];', file_get_contents('example.php'));
        $this->assertSame([], glob($this->directory.'/bsm*'));
    }

    public function test_replacement_preserves_permissions_and_refuses_multiple_hard_links(): void
    {
        file_put_contents('example.php', '<?php return [1,2];');
        chmod('example.php', 0640);
        clearstatcache(true, 'example.php');
        $mode = fileperms('example.php') & 0777;
        $this->assertTrue((new BracketSmith())->processFile('example.php'));
        clearstatcache(true, 'example.php');
        $this->assertSame($mode, fileperms('example.php') & 0777);
        if (@link('example.php', 'linked.php')) {
            file_put_contents('example.php', '<?php return [3,4];');
            clearstatcache(true, 'example.php');
            if (stat('example.php')['nlink'] > 1) {
                $this->assertFalse((new BracketSmith())->processFile('example.php'));
                $this->assertSame('<?php return [3,4];', file_get_contents('linked.php'));
            }
        }
        chmod('example.php', 0666);
    }

    public function test_bracket_spacing_is_normalized_without_reformatting_attribute_delimiters(): void
    {
        $input = "<?php\n#[A, B([1,2])]\nfunction demo() {\n    \$a=[    1, [\t2\t]    ]; \$b=[ ]; \$c=[];\n    \$d=[\n        3,\n    ];\n}\n";
        $expected = "<?php\n#[A, B([1,2])]\nfunction demo() {\n    \$a=[ 1, [ 2 ] ]; \$b=[]; \$c=[];\n    \$d=[\n        3,\n    ];\n}\n";
        file_put_contents('example.php', $input);
        $this->assertTrue((new BracketSmith())->processFile('example.php'));
        $this->assertSame($expected, file_get_contents('example.php'));
        $smith = new BracketSmith();
        $this->assertTrue($smith->processFile('example.php'));
        $this->assertSame(['processed' => 1, 'changed' => 0], $smith->getStats());
    }

    public function test_entire_attribute_groups_are_preserved_including_nested_arrays(): void
    {
        $attributes = [
            '#[Example([1])]',
            '#[Example([[1], [2]], empty: [ ], text: "] [")] #[Other([3])]',
            <<<'PHP'
#[Example([
    [1], /* ] [ */ [2],
], text: '[keep]')]
#[Other([  3  ])]
PHP,
        ];
        foreach ($attributes as $attribute) {
            $input = '<?php '.$attribute.' function demo() { return [[1]]; }';
            token_get_all($input, TOKEN_PARSE);
            file_put_contents('example.php', $input);
            [$exit] = $this->cli(['--dry-run', 'example.php']);
            $this->assertSame(0, $exit);
            $this->assertSame($input, file_get_contents('example.php'));
            [$exit] = $this->cli(['example.php']);
            $this->assertSame(0, $exit);
            $this->assertSame('<?php '.$attribute.' function demo() { return [ [ 1 ] ]; }', file_get_contents('example.php'));
            $smith = new BracketSmith();
            $this->assertTrue($smith->processFile('example.php'));
            $this->assertSame(['processed' => 1, 'changed' => 0], $smith->getStats());
        }
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

    public function test_nested_interpolated_strings_remain_valid_and_unchanged(): void
    {
        $expressions = [
            '$text = "{$obj->method("inner $arr[0]")}"',
            '$text = "{$obj->method("inner $arr[0]")} tail $arr[1]"',
            '$text = "{$obj->{"key {$arr[0]}"}} tail $arr[1]"',
            '$text = "{$obj->method("{$other->method("deep $arr[0]")}")}"',
            '$text = `echo {$obj->method("inner $arr[0]")}`',
            <<<'PHP'
$text = <<<OUTER
{$obj->method("inner $arr[0]")} tail $arr[1]
OUTER
PHP,
            <<<'PHP'
$text = "{$obj->method(<<<INNER
inner $arr[0]
INNER
)} tail $arr[1]"
PHP,
        ];
        foreach ($expressions as $expression) {
            $input = '<?php '.$expression.'; $outside=[1,2];';
            token_get_all($input, TOKEN_PARSE);
            file_put_contents('example.php', $input);
            [$exit] = $this->cli(['--dry-run', 'example.php']);
            $this->assertSame(0, $exit);
            $this->assertSame($input, file_get_contents('example.php'));
            [$exit] = $this->cli(['example.php']);
            $this->assertSame(0, $exit);
            $formatted = file_get_contents('example.php');
            token_get_all($formatted, TOKEN_PARSE);
            $this->assertSame('<?php '.$expression.'; $outside=[ 1,2 ];', $formatted);
            [$exit, $output] = $this->cli(['example.php']);
            $this->assertSame(0, $exit);
            $this->assertStringContainsString('0 of 1', $output);
        }
    }

    public function test_invalid_formatted_php_is_not_written_or_reported_as_success(): void
    {
        $input = '<?php $array=[1,2;';
        file_put_contents('example.php', $input);
        foreach ([['--dry-run', 'example.php'], ['example.php']] as $arguments) {
            [$exit] = $this->cli($arguments);
            $this->assertSame(1, $exit);
            $this->assertSame($input, file_get_contents('example.php'));
        }
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
