<?php

namespace BracketSmith;

use Exception;
use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;

class BracketSmith
{
    /**
     * Directories/files to process
     *
     * @var array
     */
    private array $include = [
        "app/",
        "config/",
        "database/",
        "lang/",
        "routes/",
    ];

    /**
     * Patterns of directories/files to skip
     *
     * @var array
     */
    private array $exclude = [
        "vendor/",
        "storage/",
        "bootstrap/cache/",
        "node_modules/",
        ".git/",
    ];

    /**
     * Counter of processed files
     *
     * @var int
     */
    private int $processed_count = 0;

    /**
     * Counter of changed files
     *
     * @var int
     */
    private int $changed_count = 0;

    /**
     * Constructor
     *
     * @param bool $dry_run
     * @param bool $verbose
     */
    public function __construct(
        /**
         * Dry-run mode (only checks, does not modify)
         *
         * @var bool
         */
        private readonly bool $dry_run = false,

        /**
         * Verbose mode (detailed output)
         *
         * @var bool
         */
        private readonly bool $verbose = false
    ) {
        $this->loadConfig();
    }

    /**
     * Load configuration from bracketsmith.json file in project root
     *
     * @return void
     */
    private function loadConfig() : void
    {
        $config_file = getcwd() . DIRECTORY_SEPARATOR . 'bracketsmith.json';

        if ( ! file_exists( $config_file ) )
            return; // Use defaults

        $config = json_decode( file_get_contents( $config_file ), true );

        if ( json_last_error() !== JSON_ERROR_NONE )
        {
            $this->log( "⚠️ Invalid JSON in bracketsmith.json: " . json_last_error_msg() );
            return;
        }

        if ( isset( $config['include'] ) && is_array( $config['include'] ) )
            $this->include = $config['include'];

        if ( isset( $config['exclude'] ) && is_array( $config['exclude'] ) )
            $this->exclude = $config['exclude'];
    }

    /**
     * Executes processing on all configured directories
     *
     * @param array $files_from_cli
     *
     * @return bool Success or general failure
     */
    public function run( array $files_from_cli = [] ) : bool
    {
        $this->log( "🔧 Processing array spacing..." );

        $success = true;

        // If files were passed via CLI, only process them
        if ( ! empty( $files_from_cli ) )
        {
            foreach ( $files_from_cli as $file )
                if ( ! $this->processFile( $file ) )
                    $success = false;
        }
        else
        {
            foreach ( $this->include as $directory )
                if ( ! $this->processDirectory( $directory ) )
                    $success = false;
        }

        $this->log( $this->getSummary() );

        return $success;
    }

    /**
     * Process a specific directory
     *
     * @param string $dir_path
     *
     * @return bool
     */
    public function processDirectory( string $dir_path ) : bool
    {
        if ( ! is_dir( $dir_path ) )
        {
            $this->log( "⚠️ Directory not found: " . $dir_path );

            return true; // Is not a fatal error
        }

        try
        {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(
                    $dir_path,
                    RecursiveDirectoryIterator::SKIP_DOTS
                )
            );

            foreach ( $iterator as $file )
            {
                if ( $file->isFile() && $file->getExtension() === "php" )
                {
                    $file_path = $file->getPathname();

                    // Skips files in specific directories
                    if ( $this->shouldSkipFile( $file_path ) )
                        continue;

                    $this->processFile( $file_path );
                }
            }

            return true;
        }
        catch ( Exception $e )
        {
            $this->log( sprintf( "❌ Error processing directory %s: ", $dir_path ) . $e->getMessage() );

            return false;
        }
    }

    /**
     * Process a specific file or directory
     *
     * @param string $path
     *
     * @return bool
     */
    public function processFile( string $path ) : bool
    {
        if ( ! file_exists( $path ) )
        {
            $this->log( "❌ Path not found: " . $path );

            return false;
        }

        // If it's a directory, process it recursively
        if ( is_dir( $path ) )
            return $this->processDirectory( $path );

        // Process single file
        $this->processed_count++;

        try
        {
            $content     = file_get_contents( $path );

            $new_content = $this->addSpacesToArrays( $content );

            if ( $content !== $new_content )
            {
                $this->changed_count++;

                if ( ! $this->dry_run )
                {
                    file_put_contents( $path, $new_content );
                    $this->verboseLog( "✅ Processed: " . $path );
                }
                else
                {
                    $this->verboseLog( "🔍 Would be processed: " . $path );
                }

                return true;
            }

            $this->verboseLog( "⏭️ No changes needed: " . $path );

            return true;
        }
        catch ( Exception $e )
        {
            $this->log( sprintf( "❌ Error processing file %s: ", $path ) . $e->getMessage() );

            return false;
        }
    }

    /**
     * Configure custom include paths
     *
     * @param array $include
     *
     * @return self
     */
    public function setInclude( array $include ) : self
    {
        $this->include = $include;

        return $this;
    }

    /**
     * Get execution statistics
     *
     * @return array
     */
    public function getStats() : array
    {
        return [
            "processed" => $this->processed_count,
            "changed"   => $this->changed_count,
        ];
    }

    /**
     * Adds spaces to single-line arrays
     *
     * @param string $content
     *
     * @return string
     */
    private function addSpacesToArrays( string $content ) : string
    {
        $max_iterations = 20;
        $iteration      = 0;

        do
        {
            $old = $content;

            $content = preg_replace_callback(
                "/\[([^\[\]\n\r]*[\\\"',][^\[\]\n\r]*)\]/",
                function ( $matches )
                {
                    return $this->processArrayMatch( $matches );
                },
                $content
            );

            $iteration++;
        }
        while ( $content !== $old && $iteration < $max_iterations );

        return $content;
    }

    /**
     * Process a specific array match
     *
     * @param array $matches
     *
     * @return string
     */
    private function processArrayMatch( array $matches ) : string
    {
        $full_match = $matches[ 0 ];
        $array_content = $matches[ 1 ];

        // Checks if the content is not empty
        if ( $this->mb_trim( $array_content ) === "" )
            return $full_match; // Empty array, do not modify

        // Check if this looks like a regex character class (contains only letters, digits, ^, -, \)
        // and does not contain quotes or commas (which would indicate a PHP array)
        if ( !preg_match( '/["\',]/', $array_content ) && preg_match( '/^[\w^\\\-]+$/', $array_content ) )
            return $full_match; // Likely a regex character class, do not modify

        // Remove leading/trailing spaces for normalization
        $trimmed_content = $this->mb_trim( $array_content );

        // Always return with single space after [ and before ]
        return "[ " . $trimmed_content . " ]";
    }

    /**
     * Multibyte-safe trim helper.
     * Provides a local mb_trim to avoid depending on a global function.
     *
     * @param string $value
     *
     * @return string
     */
    private function mb_trim( string $value ) : string
    {
        // If mbstring is available use it, otherwise fall back to trim
        if ( function_exists( 'mb_trim' ) ) {
            // If a global mb_trim exists, prefer it (backwards-compat)
            return mb_trim( $value );
        }

        if ( function_exists( 'mb_strlen' ) ) {
            // remove BOM if present
            $value = preg_replace('/\A\x{FEFF}/u', '', $value);
            // mimic trim using unicode whitespace
            return preg_replace('/^\s+|\s+\$/u', '', $value);
        }

        return trim( $value );
    }

    /**
     * Checks if a file should be skipped
     *
     * @param string $file_path
     *
     * @return bool
     */
    private function shouldSkipFile( string $file_path ) : bool
    {
        foreach ( $this->exclude as $exclude_pattern )
            if ( str_contains( $file_path, $exclude_pattern ) )
                return true;

        return false;
    }

    /**
     * Get execution summary
     *
     * @return string
     */
    private function getSummary() : string
    {
        if ( $this->dry_run )
            return sprintf( "🔍 Verification completed: %d of %d files would require changes.", $this->changed_count, $this->processed_count );

        return sprintf( "✅ Processing completed: %d of %d files were changed.", $this->changed_count, $this->processed_count );
    }

    /**
     * Normal log
     *
     * @param string $message
     *
     * @return void
     */
    private function log( string $message ) : void
    {
        echo $message . "\n";
    }

    /**
     * Verbose log (only if verbose is active)
     *
     * @param string $message
     *
     * @return void
     */
    private function verboseLog( string $message ) : void
    {
        if ( $this->verbose )
            echo $message . "\n";
    }
}
