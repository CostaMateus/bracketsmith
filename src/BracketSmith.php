<?php

namespace BracketSmith;

use Exception;
use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;

class BracketSmith
{
    /**
     * Directories to process
     *
     * @var array
     */
    private array $directories = [
        "app/",
        "config/",
        "database/",
        "lang/",
        "routes/",
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
    ) {}

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
            foreach ( $this->directories as $directory )
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
     * Configure custom directories
     *
     * @param array $directories
     *
     * @return self
     */
    public function setDirectories( array $directories ) : self
    {
        $this->directories = $directories;

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
        $tokens             = token_get_all( $content );
        $max_iterations     = 20;
        $debug_arrays_found = 0;
        $code_buffer        = "";
        $parts              = [];

        // 1. Separate pure code from strings/comments, keeping the order
        foreach ( $tokens as $token )
        {
            if ( is_array( $token ) )
            {
                $id   = $token[ 0 ];
                $text = $token[ 1 ];

                if ( ! in_array( $id, [ T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_START_HEREDOC, T_END_HEREDOC, T_DOC_COMMENT, T_COMMENT ], true ) )
                {
                    $code_buffer .= $text;
                }
                else
                {
                    // When finding string/comment, save the accumulated code before
                    if ( $code_buffer !== "" )
                    {
                        $parts[]    = [
                            "type" => "code",
                            "text" => $code_buffer
                        ];

                        $code_buffer = "";
                    }

                    $parts[] = [
                        "type" => "literal",
                        "text" => $text
                    ];
                }
            }
            else
            {
                $code_buffer .= $token;
            }
        }

        if ( $code_buffer !== "" )
        {
            $parts[] = [
                "type" => "code",
                "text" => $code_buffer
            ];
        }

        // 2. Process only code segments
        foreach ( $parts as &$part )
        {
            if ( $part[ "type" ] === "code" )
            {
                $iteration = 0;

                do
                {
                    $old = $part[ "text" ];

                    $part[ "text" ] = preg_replace_callback(
                        "/\[([^\[\]\n\r]*)\]/",
                        function ( $matches ) use ( &$debug_arrays_found )
                        {
                            $debug_arrays_found++;

                            return $this->processArrayMatch( $matches );
                        },
                        $part[ "text" ]
                    );

                    $iteration++;
                }
                while ( $part[ "text" ] !== $old && $iteration < $max_iterations );
            }
        }

        unset( $part );

        // 3. Rebuilds the file
        $out = "";

        foreach ( $parts as $part )
            $out .= $part[ "text" ];

        return $out;
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
        if ( mb_trim( $array_content ) === "" )
            return $full_match; // Empty array, do not modify

        // Remove leading/trailing spaces for normalization
        $trimmed_content = mb_trim( $array_content );

        // Always return with single space after [ and before ]
        return "[ " . $trimmed_content . " ]";
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
        $skip_patterns = [
            "vendor/",
            "storage/",
            "bootstrap/cache/",
            "node_modules/",
            ".git/",
        ];

        foreach ( $skip_patterns as $skip_pattern )
            if ( str_contains( $file_path, $skip_pattern ) )
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
