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
        ];
    }

    /**
     * @dataProvider css_that_must_survive
     */
    public function test_css_keeps_its_meaning($in, $out)
    {
        $this->assertSame($out, mo_minify_css($in));
    }

    public function test_html_collapses_text_but_keeps_tags_and_kept_blocks()
    {
        $in = "<div  class=\"a  b\" data-x='{\"t\": \"two  spaces\"}'>\n   Hello\n\n   <b>world</b>  !<!-- gone --><!--[if mso]><p>x</p><![endif]-->"
              . "<pre>  keep\n  this </pre><script>var  a = 1;\n</script><textarea>  t  </textarea></div>";

        $out = mo_minify_html($in);

        $this->assertStringContainsString("<div  class=\"a  b\" data-x='{\"t\": \"two  spaces\"}'>", $out, 'Attributes are untouched.');
        $this->assertStringContainsString("\nHello\n<b>world</b> !", $out, 'Text runs collapse; a run with a line break keeps one.');
        $this->assertStringNotContainsString('gone', $out, 'Plain comments go.');
        $this->assertStringContainsString('<!--[if mso]><p>x</p><![endif]-->', $out, 'Conditional comments stay.');
        $this->assertStringContainsString("<pre>  keep\n  this </pre><script>var  a = 1;\n</script><textarea>  t  </textarea>", $out);
    }
}
