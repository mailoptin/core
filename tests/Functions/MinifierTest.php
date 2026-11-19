<?php

namespace MailOptin\Core\Tests\Functions;

/**
 * The CSS and HTML minifiers used for optin forms. They had never run: their patterns were file-level variables
 * read with `global`, and Composer loads the file inside a function. Turning them on exposed rules that broke
 * real CSS, so they now only drop comments and white-space that can't matter.
 */
class MinifierTest extends \WP_UnitTestCase
{
    public function test_it_runs_when_loaded_by_composer()
    {
        // Under PHPUnit the file is loaded by Composer's autoloader, as in production.
        $this->assertSame('a{color:red}', mo_minify_css("a {\n  color: red;\n}\n/* note */"));
    }

    public function css_that_must_survive()
    {
        return [
            'media query keeps the space before ('   => ['@media screen and (max-width: 480px) { a { color: red } }', '@media screen and (max-width:480px){a{color:red}}'],
            'supports not ('                         => ['@supports not (display: grid) { a { float: left } }', '@supports not (display:grid){a{float:left}}'],
            'keyframe 0% stays 0%'                   => ['@keyframes f { 0% { opacity: 0 } 100% { opacity: 1 } }', '@keyframes f{0%{opacity:0}100%{opacity:1}}'],
            'hex colours starting with 0'            => ['a { color: #0ff; background: #0abcde }', 'a{color:#0ff;background:#0abcde}'],
            'descendant after :not() keeps its space'=> ['a:not(.b) .c { color: red }', 'a:not(.b) .c{color:red}'],
            'space before a pseudo-class'            => ['div :hover { color: red }', 'div :hover{color:red}'],
            'time units stay'                        => ['a { transition: opacity 0s ease; animation-delay: 0ms }', 'a{transition:opacity 0s ease;animation-delay:0ms}'],
            'flex basis and origin stay'             => ['a { flex: 1 1 0%; transform-origin: 0 0 }', 'a{flex:1 1 0%;transform-origin:0 0}'],
            'calc keeps its operators'               => ['a { width: calc(100% - 20px) }', 'a{width:calc(100% - 20px)}'],
            'quoted data URL stays quoted'           => ["a { filter: url(\"data:image/svg+xml;utf9,<svg%20version='1.1'></svg>#blur\") }", "a{filter:url(\"data:image/svg+xml;utf9,<svg%20version='1.1'></svg>#blur\")}"],
            'strings are left alone'                 => ['a::before { content: "a  ;  b" }', 'a::before{content:"a  ;  b"}'],
            'important comments stay'                => ['/*! keep */ a { b: c }', '/*! keep */ a{b:c}'],
            'a stray quote ends at the line break'   => ["// don't\na{color:red}\nb{color:blue}", "// don't\na{color:red}b{color:blue}"],
            'an open string does not pair quotes'    => ["a{content:\"x\n}\nb{content:\"a ; b\"}", "a{content:\"x\n}b{content:\"a ; b\"}"],
            'unquoted url with /* is not a comment'  => ['a{background:url(http://x.com/a/*/b.png) no-repeat}', 'a{background:url(http://x.com/a/*/b.png) no-repeat}'],
            'escapes keep the space after them'      => ['.a\\, .b{x:y} .\\31  .c{x:y}', '.a\\, .b{x:y}.\\31  .c{x:y}'],
            'an empty custom property keeps a space' => [':root{--x: ;--y: red}', ':root{--x: ;--y:red}'],
        ];
    }

    /**
     * @dataProvider css_that_must_survive
     */
    public function test_css_keeps_its_meaning($in, $out)
    {
        $this->assertSame($out, mo_minify_css($in));
    }

    public function html_cases()
    {
        return [
            'text collapses, attributes stay'     => ["<div  class=\"a  b\" data-x='{\"t\": \"two  spaces\"}'>\n   Hello\n\n   <b>world</b>  !</div>", "<div  class=\"a  b\" data-x='{\"t\": \"two  spaces\"}'>\nHello\n<b>world</b> !</div>"],
            'kept blocks stay as written'         => ["<pre>  keep\n  this </pre><script>var  a = 1;\n</script><textarea>  t  </textarea>", "<pre>  keep\n  this </pre><script>var  a = 1;\n</script><textarea>  t  </textarea>"],
            'plain comments go'                   => ['<p>a</p><!-- gone --><p>b</p>', '<p>a</p><p>b</p>'],
            'conditional comments stay, both ends'=> ['<!--[if !mso]><!--><p>a</p><!--<![endif]-->', '<!--[if !mso]><!--><p>a</p><!--<![endif]-->'],
            '<!-- in an attribute is not a comment' => ['<div data-x="<!--">keep  me</div><p>more</p>', '<div data-x="<!--">keep me</div><p>more</p>'],
            'an abrupt comment only drops itself' => ['<!-->hello<!-- x --> world', 'hello world'],
            '> in an attribute keeps the tag whole' => ["<input title=\"a >\n  b\" placeholder=\"x  y<z\">  text", "<input title=\"a >\n  b\" placeholder=\"x  y<z\"> text"],
            'pre styling leaves the markup alone' => ['<div style="white-space:pre">a    b</div>', '<div style="white-space:pre">a    b</div>'],
        ];
    }

    /**
     * @dataProvider html_cases
     */
    public function test_html_keeps_its_meaning($in, $out)
    {
        $this->assertSame($out, mo_minify_html($in));
    }

    public function test_input_past_the_backtracking_limit_comes_back_whole()
    {
        // A kept block or comment over PCRE's backtracking limit (1,000,000) used to make the whole optin vanish.
        $big = str_repeat('x', 1100000);

        $html = mo_minify_html("<div><script>$big</script><form>  f  </form></div>");
        $this->assertStringEndsWith('<form> f </form></div>', $html);
        $this->assertSame(1100000 + 44, strlen($html));

        $this->assertSame('<div><b> k </b></div>', mo_minify_html("<div><!--$big--><b>  k </b></div>"));

        $css = "a { background: url('data:image/png;base64,$big') }";
        $this->assertSame("a{background:url('data:image/png;base64,$big')}", mo_minify_css($css));
    }
}
