<?php namespace SSD\DotEnv;

use InvalidArgumentException;

class Loader
{
    /**
     * @var array
     */
    private $files = [ ];
    /**
     * @var bool
     */
    private $immutable = false;
    /**
     * @var array
     */
    private $lines = [ ];

    /**
     * @param array $files
     * @param bool|false $immutable
     */
    public function __construct(array $files, $immutable = false)
    {
        $this->files = $files;
        $this->immutable = $immutable;
    }

    /**
     * Load files and set variables.
     *
     * @return void
     */
    public function load()
    {
        $this->getContent();

        $this->processEntries();
    }

    /**
     * Process collection of the given files.
     *
     * @return void
     */
    private function getContent()
    {
        foreach ($this->files as $file) {

            $this->validateFile($file);

            $this->readFileContent($file);

        }
    }

    /**
     * Determine if the file exists and is readable.
     *
     * @param string $file
     * @throws InvalidArgumentException
     * @return void
     */
    private function validateFile($file)
    {
        if ( ! is_file($file) || ! is_readable($file)) {

            throw new InvalidArgumentException(
                sprintf(
                    'File "%s" cannot be found / cannot be read.',
                    $file
                )
            );

        }
    }

    /**
     * Read content of a file
     * and add its lines to the collection.
     *
     * @param string $file
     * @return void
     */
    private function readFileContent($file)
    {
        $autodetect = ini_get('auto_detect_line_endings');
        ini_set('auto_detect_line_endings', '1');
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        ini_set('auto_detect_line_endings', $autodetect);

        $this->lines = array_merge($this->lines, $lines);
    }

    /**
     * Validate lines fetch from the files
     * and set variables accordingly.
     *
     * @return void
     */
    private function processEntries()
    {
        if (empty($this->lines)) {
            return;
        }

        foreach ($this->lines as $line) {

            if ($this->isLineComment($line) || ! $this->isLineSetter($line)) {
                continue;
            }

            $this->setVariable($line);

        }
    }

    /**
     * Determine if the given line contains a "#" symbol at the beginning.
     *
     * @param string $line
     * @return bool
     */
    private function isLineComment($line)
    {
        return strpos(trim($line), '#') === 0;
    }

    /**
     * Determine if the given line contains an "=" symbol.
     *
     * @param string $line
     * @return bool
     */
    protected function isLineSetter($line)
    {
        return strpos($line, '=') !== false;
    }

    /**
     * Set environment variable.
     *
     * Using:
     * - putenv
     * - $_ENV
     * - $_SERVER
     *
     * Value is stripped of single and double quotes.
     *
     * @param string $name
     * @param null $value
     */
    public function setVariable($name, $value = null)
    {
        list($name, $value) = $this->normaliseVariable($name, $value);

        if ($this->immutable && ! is_null($this->getVariable($name))) {
            return;
        }

        putenv("{$name}={$value}");
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }

    /**
     * Normalise given name and value.
     *
     * - Splits the string using "=" symbol
     * - Strips the quotes from name and value
     * - Resolves nested variables
     *
     * @param string $name
     * @param mixed $value
     * @return array
     */
    private function normaliseVariable($name, $value)
    {
        list($name, $value) = $this->splitStringIntoParts($name, $value);
        list($name, $value) = $this->sanitiseVariableName($name, $value);
        list($name, $value) = $this->sanitiseVariableValue($name, $value);
        $value = $this->resolveNestedVariables($value);

        return [ $name, $value ];
    }

    /**
     * Split name into parts.
     *
     * If the "$name" contains a "=" symbol
     * we split it into "$name" and "$value"
     * disregarding "$value" argument of the method.
     *
     * @param string $name
     * @param mixed $value
     * @return array
     */
    private function splitStringIntoParts($name, $value)
    {
        if (strpos($name, '=') !== false) {
            list($name, $value) = array_map('trim', explode('=', $name, 2));
        }

        return [ $name, $value ];
    }

    /**
     * Strip quotes and the optional leading "export"
     * from the name.
     *
     * @param string $name
     * @param mixed $value
     * @return array
     */
    private function sanitiseVariableName($name, $value)
    {
        $name = trim(str_replace([ 'export ', '\'', '"' ], '', $name));

        return [ $name, $value ];
    }

    /**
     * Strip quotes from the value.
     *
     * @param string $name
     * @param mixed $value
     * @return array
     */
    private function sanitiseVariableValue($name, $value)
    {
        $value = trim($value);

        if ( ! $value) {
            return [ $name, $value ];
        }

        $value = $this->getSanitisedValue($value);

        return [ $name, trim($value) ];
    }

    /**
     * Return value without the quotes.
     *
     * @param mixed $value
     * @return mixed|string
     * @throws InvalidArgumentException
     */
    private function getSanitisedValue($value)
    {
        if ($this->beginsWithQuote($value)) {

            $quote = $value[0];

            $regexPattern = sprintf(
                '/^
                %1$s          # match a quote at the start of the value
                (             # capturing sub-pattern used
                 (?:          # we do not need to capture this
                  [^%1$s\\\\] # any character other than a quote or backslash
                  |\\\\\\\\   # or two backslashes together
                  |\\\\%1$s   # or an escaped quote e.g \"
                 )*           # as many characters that match the previous rules
                )             # end of the capturing sub-pattern
                %1$s          # and the closing quote
                .*$           # and discard any string after the closing quote
                /mx',
                $quote
            );

            $value = preg_replace($regexPattern, '$1', $value);
            $value = str_replace("\\$quote", $quote, $value);
            $value = str_replace('\\\\', '\\', $value);

            return $value;
        }

        $parts = explode(' #', $value, 2);
        $value = trim($parts[0]);

        if (preg_match('/\s+/', $value) > 0) {
            throw new InvalidArgumentException('DotEnv values containing spaces must be surrounded by quotes.');
        }

        return $value;
    }

    /**
     * Determine if the given value begins with a single or double quote.
     *
     * @param mixed $value
     * @return bool
     */
    private function beginsWithQuote($value)
    {
        return strpbrk($value[0], '"\'') !== false;
    }

    /**
     * Return nested variable.
     *
     * Look for {$varname} patterns in the variable value
     * and replace with an existing environment variable.
     *
     * @param mixed $value
     * @return mixed
     */
    private function resolveNestedVariables($value)
    {
        if (strpos($value, '$') === false) {
            return $value;
        }

        $loader = $this;

        return preg_replace_callback(
            '/\${([a-zA-Z0-9_]+)}/',
            function ($matchedPatterns) use ($loader) {
                $nestedVariable = $loader->getVariable($matchedPatterns[1]);
                if (is_null($nestedVariable)) {
                    return $matchedPatterns[0];
                }

                return $nestedVariable;
            },
            $value
        );
    }

    /**
     * Get environment variable by name
     * from either:
     *
     * - $_ENV
     * - $_SERVER
     * - getenv
     *
     * @param string $name
     * @return null|string
     */
    public function getVariable($name)
    {
        switch (true) {
            case array_key_exists($name, $_ENV):
                return $_ENV[$name];
            case array_key_exists($name, $_SERVER):
                return $_SERVER[$name];
            default:
                $value = getenv($name);

                return $value === false ? null : $value;
        }
    }

    /**
     * Clear environment variable by name.
     *
     * @param string $name
     */
    public function clearVariable($name)
    {
        if ($this->immutable) {
            return;
        }

        putenv($name);
        unset($_ENV[$name]);
        unset($_SERVER[$name]);
    }

}