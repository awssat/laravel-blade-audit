<?php
namespace Awssat\BladeAudit;


use Illuminate\Support\Str;
use Illuminate\Support\Collection;
use Illuminate\Container\Container;
use Illuminate\Contracts\View\View;
use Illuminate\Contracts\View\Factory;
use Illuminate\View\Compilers\BladeCompiler;


class Analyze
{
    /** @var \Illuminate\View\Compilers\BladeCompiler */
    protected $compiler;

    /** @var \Illuminate\View\FileViewFinder */
    protected $finder;

    protected $code;

    /** @var \Illuminate\Support\Collection */
    protected $directives;

    protected $viewInfo;
    protected $nestedLevels;
    protected $directivesInfo;
    protected $warnings;

    public function __construct()
    {
        $app = Container::getInstance();

        $this->compiler = $app->make(BladeCompiler::class);
        $this->finder = $app->make(Factory::class)->getFinder();
    }

    /**
     * @return self
     */
    public static function view($viewName)
    {
        return (new static())->analyze($viewName);
    }

    public function analyze($viewName)
    {
        try {
            $viewPath = $this->finder->find($viewName);
        } catch (\InvalidArgumentException $e) {
            return false;
        }

        $this->code = file_get_contents($viewPath);

        preg_match_all(
            '/\B@(@?\w+(?:::\w+)?)([ \t]*)(\( ( (?>[^()]+) | (?3) )* \))?/x',
            $this->code,
            $m,
            PREG_SET_ORDER
        );

        $this->directives = Collection::wrap($m)
            ->map(function ($v) {
                return [$v[0], strtolower($v[1])];
            });

        $this->warnings = Collection::make();

        $this->calculateNestingLevel();
        $this->fetchViewInfo();
        $this->fetchDirectivesInfo();
        $this->detectWarnings();

        return $this;
    }

    protected function calculateNestingLevel()
    {
        $currentNestingLevel = 0;
        $nestedDirectives = Collection::make();

        foreach ($this->directives as $item) {

            [$code, $directive] = $item;

            if (Str::startsWith($directive, ['end', 'stop'])) {
                if ($currentNestingLevel > 0) {
                    $currentNestingLevel--;
                }

                $nestedDirectives->push([$directive, $currentNestingLevel]);
            } elseif (Str::startsWith($directive, 'else')) {
                $nestedDirectives->push([$directive, $currentNestingLevel > 0 ? $currentNestingLevel - 1 : 0]);
            } else {
                $nestedDirectives->push([$directive, $currentNestingLevel]);

                if ($this->isBlockDirective($code)) {
                    $currentNestingLevel++;
                }
            }
        }

        $this->nestedLevels = $nestedDirectives;
    }

    protected function fetchDirectivesInfo()
    {
        $this->directivesInfo = Collection::wrap(array_count_values($this->directives->pluck(1)->toArray()));

        $this->directivesInfo = $this->directivesInfo->map(function ($v, $k) {
            $customDirective = !method_exists(BladeCompiler::class, 'compile' . $k);

            return [$k, $v, $customDirective ? 'custom' : 'built-in'];
        });
    }

    protected function fetchViewInfo()
    {
        $this->viewInfo = Collection::make();

        //file info
        $this->viewInfo->push(['Size (bytes)', strlen($this->code)]);

        $linesNumber = substr_count($this->code, "\n");

        $this->viewInfo->push(['Lines', $linesNumber]);

        if ($linesNumber > 300) {
            $this->warnings->push(['lines > 300', sprintf('View has %d lines, it\'s a good idea to seperate & @include codes.', $linesNumber)]);
        }

        $lines = array_map('\\Illuminate\\Support\\Str::length', explode("\n", $this->code));

        $this->viewInfo->push(['Longest Line (chars)', max($lines)]);

        //directives number
        $directivesNumber = $this->directives
            ->filter(function ($item) {
                return !Str::startsWith($item[0], ['end', 'stop', 'else']);
            })->count();

        $this->viewInfo->push(['Directives', $directivesNumber]);

        //html & css
        if (class_exists(\DOMDocument::class) && !empty($this->code)) {
            $dom = new \DOMDocument();
            $dom->loadHTML($this->code, LIBXML_NOERROR | LIBXML_NONET | LIBXML_NOWARNING);
            $allElements = $dom->getElementsByTagName('*');

            $this->viewInfo->push(['HTML elements', $allElements->length]);
        }
    }

    protected function detectWarnings()
    {
        //php directive?
        if (strpos($this->code, '@php') !== false) {
            $this->warnings->push(['@php', 'Is not recommended to use php codes directly in your view.']);
        }

        //not a good idea things
        if (strpos($this->code, '__DIR__') !== false) {
            $this->warnings->push(['__DIR__', 'Avoid using __DIR__ because it refers to the location of cache folder, not the view.']);
        }

        if (strpos($this->code, '__FILE__') !== false) {
            $this->warnings->push(['__FILE__', 'Avoid using __FILE__ because it\'s cached file\'s location, not the view.']);
        }

        //new stuff
        $laravelVersion = Container::getInstance()::VERSION;

        if (version_compare($laravelVersion, '5.6', '>=')) {
            if (preg_match('/\{\{\s+csrf_field/', $this->code)) {
                $this->warnings->push(['csrf_field', 'You could use @csrf instead of {{ csrf_field() }}']);
            }

            if (preg_match('/\{\{\s+method_field/', $this->code)) {
                $this->warnings->push(['method_field', 'You could use @method(..) instead of {{ method_field(..) }}']);
            }
        }

        if (version_compare($laravelVersion, '5.7', '>=')) {
            if (
                preg_match('/@?{{(\s*((?!}}).?)*\s*)or\s*.+?\s*}}(\r?\n)?/s', $this->code) ||
                preg_match('/@?{{{(\s*((?!}}}).?)*\s*)or\s*.+?\s*}}}(\r?\n)?/s', $this->code) ||
                preg_match('/@?{\!\!(\s*((?!\!\!}).?)*\s*)or\s*.+?\s*\!\!}(\r?\n)?/s', $this->code) 
                ) {
                $this->warnings->push(['or_operator', 'The "or" operator has been removed in favor of ?? as in {{ $var ?? "" }}']);
            }
        }

        if (version_compare($laravelVersion, '5.8.13', '>=')) {
            if (preg_match('/\@if\s*\(\s*\$errors->has\s*\(/s', $this->code)) {
                $this->warnings->push(['error_directive', 'You could use @error(\'..\') instead of @if($error->has(..)).']);
            }
        }

        //execution time
        $executionStartTime = microtime(true);
        $this->compiler->compileString($this->code);
        $executionTime = microtime(true) - $executionStartTime;

        if ($executionTime > 0.7) {
            $this->warnings->push(['Compiler', sprintf('Compiling time (%01.2f seconds) could be better.', $executionTime)]);
        }

        //excceive using of {{!! !!}} > 2
        if (($rawEchos = preg_match_all('/\{\{\!\!\s*(.+?)\s*\!\!\}\}/s', $this->code)) > 2) {
            $this->warnings->push(['{{!! .. !!}}', sprintf('You are using raw echos (un-escaped print) %d times, be careful.', $rawEchos)]);
        }
    }

    protected function isBlockDirective($code)
    {
        try {
            return Str::contains($this->compiler->compileString($code), [' if', ' for', ' foreach', ' else', '->startSection', '->startComponent', ' while']);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * @return Illuminate\Support\Collection
     */
    public function getViewInfo()
    {
        return $this->viewInfo;
    }

    /**
     * @return Illuminate\Support\Collection
     */
    public function getDirectivesInfo()
    {
        return $this->directivesInfo;
    }

    /**
     * @return Illuminate\Support\Collection
     */
    public function getNestedLevels()
    {
        return $this->nestedLevels;
    }

    /**
     * @return Illuminate\Support\Collection
     */
    public function getWarnings()
    {
        return $this->warnings;
    }
}
