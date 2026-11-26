<?php

namespace BracketSmith\Tests;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public static function bracketContexts(): iterable
    {
        yield 'nested compact arrays' => ['$a=[[1],[2]];', '$a=[ [ 1 ],[ 2 ] ];'];
        yield 'empty arrays and append offsets' => ['$a=[ ]; $a[][]=1;', '$a=[]; $a[][]=1;'];
        yield 'mixed nested keys' => ["\$a=['x'=>[1,2],0=>[]];", "\$a=[ 'x'=>[ 1,2 ],0=>[] ];"];
        yield 'array unpacking and trailing comma' => ['$a=[...$left, ...$right,];', '$a=[ ...$left, ...$right, ];'];
        yield 'nested dynamic offsets' => ['$a=$items[$keys[$indices[0]]];', '$a=$items[ $keys[ $indices[ 0 ] ] ];'];
        yield 'string offset' => ['$value="abc"[0];', '$value="abc"[ 0 ];'];
        yield 'quoted offset with literal brackets' => ['$value=$items["[key]"];', '$value=$items[ "[key]" ];'];
        yield 'null coalescing and nullsafe access' => ['$a=$obj?->items()[0] ?? [1];', '$a=$obj?->items()[ 0 ] ?? [ 1 ];'];
        yield 'nested destructuring with holes' => ['[$a,,[$b]]=$rows;', '[ $a,,[ $b ] ]=$rows;'];
        yield 'keyed reference destructuring' => ['["x"=>&$a]=$row;', '[ "x"=>&$a ]=$row;'];
        yield 'legacy list with nested brackets' => ['list($a,[$b])=[1,[2]];', 'list($a,[ $b ])=[ 1,[ 2 ] ];'];
        yield 'foreach destructuring' => ['foreach($rows as [$a,$b]) { $out[]=[$a,$b]; }', 'foreach($rows as [ $a,$b ]) { $out[]=[ $a,$b ]; }'];
        yield 'arrow functions and match' => ['$fn=fn($x)=>match($x) { 1=>[2], default=>[] };', '$fn=fn($x)=>match($x) { 1=>[ 2 ], default=>[] };'];
        yield 'yield values' => ['$fn=function() { yield [1]; yield from [[2]]; };', '$fn=function() { yield [ 1 ]; yield from [ [ 2 ] ]; };'];
        yield 'anonymous class and promoted default' => ['$obj=new class([1]) { public function __construct(public array $a=[2]) {} };', '$obj=new class([ 1 ]) { public function __construct(public array $a=[ 2 ]) {} };'];
        yield 'enum method' => ['enum Choice { case A; public function values(): array { return [self::A]; } }', 'enum Choice { case A; public function values(): array { return [ self::A ]; } }'];
        yield 'spaces and tabs' => ["\$a=[\t1,[  2  ]\t];", '$a=[ 1,[ 2 ] ];'];
        yield 'multiline indentation' => ["\$a=[\n\t[1],\n    [2],\n];", "\$a=[\n\t[ 1 ],\n    [ 2 ],\n];"];
        yield 'CRLF indentation' => ["\$a=[\r\n    [1],\r\n];", "\$a=[\r\n    [ 1 ],\r\n];"];
        yield 'empty multiline array' => ["\$a=[\n    ];", "\$a=[\n    ];"];
        yield 'block comments inside arrays' => ['$a=[/* [keep] */1/* ] */];', '$a=[ /* [keep] */1/* ] */ ];'];
        yield 'line comment inside array' => ["\$a=[1, // [keep]\n    2];", "\$a=[ 1, // [keep]\n    2 ];"];
        yield 'inline HTML and script' => ['?> <script>const x = [1,2];</script><?php $a=[1];', '?> <script>const x = [1,2];</script><?php $a=[ 1 ];'];
        yield 'readonly class' => ['readonly class Fixture { public function __construct(public array $a=[1]) {} }', 'readonly class Fixture { public function __construct(public array $a=[ 1 ]) {} }', 80200];
        yield 'typed class constant' => ['class Fixture { public const array ITEMS=[1]; }', 'class Fixture { public const array ITEMS=[ 1 ]; }', 80300];
        yield 'property hook' => ['class Fixture { public array $items { get => [1]; } }', 'class Fixture { public array $items { get => [ 1 ]; } }', 80400];

        $preserved = [
            'single-quoted string' => <<<'PHP'
$s='[1,2] and \'[escaped]\'';
PHP,
            'regex and JSON literals' => <<<'PHP'
$pattern='/[a-z,\[\]]+/'; $json='{"items":[1,2]}';
PHP,
            'plain interpolation' => '$s="value $items[0] and {$items[1]}";',
            'nested interpolation' => '$s="{$obj->method("inner $items[0]")} tail $items[1]";',
            'interpolated dynamic property' => '$s="{$obj->{"key {$items[0]}"}}";',
            'shell string' => '$s=`echo {$obj->method("inner $items[0]")}`;',
            'comments' => <<<'PHP'
/* [1,2] */
// [keep]
PHP,
            'nowdoc' => <<<'PHP'
$s=<<<'TEXT'
[literal] $items[0]
TEXT;
PHP,
            'heredoc with nested interpolation' => <<<'PHP'
$s=<<<TEXT
{$obj->method("inner $items[0]")} tail [literal]
TEXT;
PHP,
            'nested heredoc' => <<<'PHP'
$s="{$obj->method(<<<TEXT
inner $items[0]
TEXT
)} tail $items[1]";
PHP,
            'attribute nested arrays' => '#[Example([[1],[2]], empty: [ ])] function sample() {}',
            'multiple attribute groups' => '#[A([1]), B([2])] #[C([3])] function sample() {}',
            'attribute strings and comments' => <<<'PHP'
#[A([ /* ] */ [1], [2] ], text: '] [')]
function sample() {}
PHP,
        ];
        foreach ($preserved as $name => $snippet) {
            yield $name => [$snippet."\n".'$outside=[1];', $snippet."\n".'$outside=[ 1 ];'];
        }
    }

    private static function semanticTokens(string $source): array
    {
        $tokens = array_filter(token_get_all($source, TOKEN_PARSE), static fn($token) => !is_array($token) || $token[0] !== T_WHITESPACE);
        return array_values(array_map(static fn($token) => is_array($token) ? [$token[0], $token[1]] : $token, $tokens));
    }

    #[DataProvider('bracketContexts')]
    public function test_contexts_preserve_tokens_and_produce_idempotent_expected_output(string $input, string $expected, int $minimumPhp = 80100): void
    {
        if (PHP_VERSION_ID < $minimumPhp) {
            $this->markTestSkipped('This fixture requires PHP '.$minimumPhp.' syntax.');
        }
        $input = "<?php\n".$input;
        $expected = "<?php\n".$expected;
        $tokens = self::semanticTokens($input);
        file_put_contents('example.php', $input);
        $this->assertTrue((new BracketSmith(true))->processFile('example.php'));
        $this->assertSame($input, file_get_contents('example.php'));
        $this->assertTrue((new BracketSmith())->processFile('example.php'));
        $formatted = file_get_contents('example.php');
        $this->assertSame($expected, $formatted);
        $this->assertSame($tokens, self::semanticTokens($formatted));
        $smith = new BracketSmith();
        $this->assertTrue($smith->processFile('example.php'));
        $this->assertSame(['processed' => 1, 'changed' => 0], $smith->getStats());
        $this->assertSame($formatted, file_get_contents('example.php'));
    }

    public static function executableCases(): iterable
    {
        yield 'arrays and unpacking' => ['$a=[1,2]; return [...$a,3];', [1,2,3]];
        yield 'nested offsets' => ['$a=[[10,20],[30]]; return $a[0][1];', 20];
        yield 'append and string offsets' => ['$a=[]; $a[]="abc"; return $a[0][1];', 'b'];
        yield 'destructuring references' => ['$row=["x"=>1]; ["x"=>&$x]=$row; $x=2; return $row;', ['x'=>2]];
        yield 'foreach destructuring' => ['$out=[]; foreach([[1,2],[3,4]] as [$a,$b]) { $out[]=[$b,$a]; } return $out;', [[2,1],[4,3]]];
        yield 'match and arrow' => ['$f=fn($x)=>match($x) { 1=>[2,3], default=>[] }; return $f(1);', [2,3]];
        yield 'nested interpolation value' => [
            '$obj=new class { public function method($value) { return $value; } }; $items=["safe"]; return "{$obj->method("inner $items[0]")}";',
            'inner safe',
        ];
    }

    #[DataProvider('executableCases')]
    public function test_formatting_preserves_results_of_controlled_php_snippets(string $body, mixed $expected): void
    {
        $input = '<?php '.$body;
        file_put_contents('original.php', $input);
        file_put_contents('formatted.php', $input);
        $this->assertTrue((new BracketSmith())->processFile('formatted.php'));
        // Execute only these literal test snippets, never arbitrary project source.
        $this->assertSame($expected, (static fn($path) => require $path)($this->directory.'/original.php'));
        $this->assertSame($expected, (static fn($path) => require $path)($this->directory.'/formatted.php'));
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
