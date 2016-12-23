<?php
namespace Kaoken\MarkdownIt\RulesBlock;

use Kaoken\MarkdownIt\Common\Utils;
use Kaoken\MarkdownIt\Token;

class StateBlock
{
    public $src = '';

    /**
     * @var \Kaoken\MarkdownIt\MarkdownIt
     */
    public $md     = null;

    /**
     * @var object
     */
    public $env;

    /**
     * @var Token[]
     */
    public $tokens = [];

    /**
     * @var array Line begin offsets for fast jumps
     */
    public $bMarks = [];
    /**
     * @var array Line end offsets for fast jumps
     */
    public $eMarks = [];
    /**
     * @var array Offsets of the first non-space characters (tabs not expanded)
     */
    public $tShift = [];
    /**
     * @var array Indents for each line (tabs expanded)
     */
    public $sCount = [];

    /**
     * An amount of virtual spaces (tabs expanded) between beginning
     * of each line (bMarks) and real beginning of that line.
     *
     * It exists only as a hack because blockquotes override bMarks
     * losing information in the process.
     *
     * It's used only when expanding tabs, you can think about it as
     * an initial tab length, e.g. bsCount=21 applied to string `\t123`
     * means first tab should be expanded to 4-21%4 === 3 spaces.
     * @var array
     */
    public $bsCount = [];

    // block parser variables
    /**
     * @var int Required block content indent
     */
    public $blkIndent  = 0;
    // (for example, if we are in list)
    /**
     * @var int Line index in src
     */
    public $line       = 0;
    /**
     * @var int|string Lines count
     */
    public $lineMax    = 0;
    /**
     * loose/tight mode for lists
     * @var bool
     */
    public $tight      = false;
    /**
     * indent of the current dd block (-1 if there isn't any)
     * @var int
     */
    public $ddIndent   = -1;

    /**
     * can be 'blockquote', 'list', 'root', 'paragraph' or 'reference'
     * used in lists to determine if they interrupt a paragraph
     * @var string
     */
    public $parentType = 'root';

    public $level = 0;

    // renderer
    public $result = '';


    /**
     * StateBlock constructor.
     * @param string $src
     * @param \Kaoken\MarkdownIt\MarkdownIt $this->md
     * @param object  $env
     * @param Token[] $tokens
     */
    public function __construct($src, $md, $env, &$tokens)
    {
        
        $this->src = $src;
        $this->md     = $md;
        $this->env = $env;
        $this->tokens = &$tokens;


        // Create caches
        // Generate markers.
        $indent_found = false;

        $nextLen = 1;
        for ($start = $pos = $indent = $offset = 0, $len = strlen ($this->src); $pos < $len; $pos++) {
            $ch = $this->src[$pos];

            if (!$indent_found) {
                if ($this->md->utils->isSpace($ch)) {
                    $indent++;

                    if ($ch === "\t") {
                        $offset += 4 - $offset % 4;
                    } else {
                        $offset++;
                    }
                    continue;
                } else {
                    $indent_found = true;
                }
            }

            if ($ch === "\n" || $pos === $len - 1) {
                if ($ch !== "\n") { $pos++; }
                $this->bMarks[] = $start;
                $this->eMarks[] = $pos;
                $this->tShift[] = $indent;
                $this->sCount[] = $offset;
                $this->bsCount[] = 0;

                $indent_found = false;
                $indent = 0;
                $offset = 0;
                $start = $pos + 1;
            }
        }

        // Push fake entry to simplify cache bounds checks
        $this->bMarks[] = strlen($this->src);
        $this->eMarks[] = strlen($this->src);
        $this->tShift[] = 0;
        $this->sCount[] = 0;
        $this->bsCount[] = 0;

        $this->lineMax = count($this->bMarks) - 1; // don't count last fake line
    }

    /**
     * @param string  $type
     * @param string  $tag
     * @param integer $nesting
     */
    public function createToken($type, $tag, $nesting)
    {
        return new Token($type, $tag, $nesting);
    }

    /**
     * @param $type
     * @param $tag
     * @param $nesting
     * @return \Kaoken\MarkdownIt\Token
     */
    public function push($type, $tag, $nesting)
    {
        $token = $this->createToken($type, $tag, $nesting);
        $token->block = true;
    
        if ($nesting < 0) { $this->level--; }
        $token->level = $this->level;
        if ($nesting > 0) { $this->level++; }
    
        $this->tokens[] = $token;
        return $token;
    }

    /**
     * @param integer $line
     * @return bool
     */
    public function isEmpty($line)
    {
        return $this->bMarks[$line] + $this->tShift[$line] >= $this->eMarks[$line];
    }

    /**
     * @param integer $from
     * @return integer
     */
    public function skipEmptyLines($from)
    {
        for ( $max = $this->lineMax; $from < $max; $from++) {
            if ($this->bMarks[$from] + $this->tShift[$from] < $this->eMarks[$from]) {
                break;
            }
        }
        return $from;
    }

    /**
     * Skip spaces from given position.
     * @param integer $pos
     * @return integer
     */
    public function skipSpaces($pos)
    {
        
        for ( $max = strlen ($this->src); $pos < $max; $pos++) {
            if (!$this->md->utils->isSpace($this->src[$pos])) { break; }
        }
        return $pos;
    }

    /**
     * Skip spaces from given position in reverse.
     * @param integer $pos
     * @param integer $min
     * @return integer
     */
    public function skipSpacesBack($pos, $min)
    {
        
        if ($pos <= $min) { return $pos; }

        while ($pos > $min) {
            if (!$this->md->utils->isSpace($this->src[--$pos])) { return $pos + 1; }
        }
        return $pos;
    }

    /**
     * Skip char codes from given position
     * @param integer $pos
     * @param string $code
     * @return integer
     */
    public function skipChars($pos, $code)
    {
        for ($max = strlen ($this->src); $pos < $max; $pos++) {
            if ($this->src[$pos] !== $code) { break; }
        }
        return $pos;
    }

    /**
     * Skip char codes reverse from given position - 1
     * @param integer $pos
     * @param string $code
     * @param integer $min
     * @return integer
     */
    public function skipCharsBack($pos, $code, $min)
    {
        if ($pos <= $min) { return $pos; }

        while ($pos > $min) {
            if ($code !== $this->src[--$pos]) { return $pos + 1; }
        }
        return $pos;
    }

    /**
     * cut lines range from source.
     * @param integer $begin
     * @param integer $end
     * @param integer $indent
     * @param boolean $keepLastLF
     * @return string
     */
    public function getLines($begin, $end, $indent, $keepLastLF)
    {
        
        $line = $begin;

        if ($begin >= $end) {
            return '';
        }

        $queue =array_fill(0, $end - $begin, '');

        for ($i = 0; $line < $end; $line++, $i++) {
            $lineIndent = 0;
            $lineStart = $first = $this->bMarks[$line];

            if ($line + 1 < $end || $keepLastLF) {
                // No need for bounds check because we have fake entry on tail.
                $last = $this->eMarks[$line] + 1;
            } else {
                $last = $this->eMarks[$line];
            }

            while ($first < $last && $lineIndent < $indent) {
                $ch = $this->src[$first];

                if ($this->md->utils->isSpace($ch)) {
                    if ($ch === "\t") {
                        $lineIndent += 4 - ($lineIndent + $this->bsCount[$line]) % 4;
                    } else {
                        $lineIndent++;
                    }
                } else if ($first - $lineStart < $this->tShift[$line]) {
                    // patched tShift masked characters to look like spaces (blockquotes, list markers)
                    $lineIndent++;
                } else {
                    break;
                }

                $first++;
            }

            if ($lineIndent > $indent) {
                // partially expanding tabs in code blocks, e.g '\t\tfoobar'
                // with indent=2 becomes '  \tfoobar'
                $queue[$i] = str_repeat(' ', $lineIndent - $indent) . substr($this->src, $first, $last-$first);
            } else {
                $queue[$i] = substr($this->src, $first, $last-$first);
            }
        }

        return join('',$queue);
    }
}