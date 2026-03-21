<?php

class __Mustache_96b15e145656c22d80c4fd9d892c8ee1 extends Mustache_Template
{
    private $lambdaHelper;

    public function renderInternal(Mustache_Context $context, $indent = '')
    {
        $this->lambdaHelper = new Mustache_LambdaHelper($this->mustache, $context);
        $buffer = '';

        $value = $this->resolveValue($context->findDot('output.standard_after_main_region_html'), $context);
        $buffer .= $indent . ($value === null ? '' : $value);
        $buffer .= '
';
        $buffer .= $indent . '<footer id="page-footer" class="';
        $value = $this->resolveValue($context->find('responsivepagefooter'), $context);
        $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
        $buffer .= '">
';
        $buffer .= $indent . '    ';
        $value = $this->resolveValue($context->findDot('output.course_footer'), $context);
        $buffer .= ($value === null ? '' : $value);
        $buffer .= '
';
        $buffer .= $indent . '    ';
        $value = $context->find('showfooterblocks');
        $buffer .= $this->section501bdf5dd075de7912c4d5eb82e5baca($context, $indent, $value);
        $buffer .= '
';
        $value = $context->find('socialicons');
        $buffer .= $this->section4357478ca66826598b1f3b2bf3307f11($context, $indent, $value);
        $buffer .= $indent . '    <div class="info container2 clearfix">
';
        $buffer .= $indent . '        <div class="container">
';
        $buffer .= $indent . '            <div class="row">
';
        $buffer .= $indent . '                <div class="tool_usertours-resettourcontainer"></div>
';
        $value = $context->find('footnote');
        $buffer .= $this->sectionB021f3ab1b4994285664fe2250f3b959($context, $indent, $value);
        $value = $context->find('moodledocs');
        $buffer .= $this->section5e1dd2c815b01a50ebb19be0d0193d4f($context, $indent, $value);
        $buffer .= $indent . '                <div class="col-md-4 my-md-0 my-2">';
        $value = $this->resolveValue($context->findDot('output.standard_footer_html'), $context);
        $buffer .= ($value === null ? '' : $value);
        $buffer .= '</div>
';
        $buffer .= $indent . '            </div>
';
        $value = $context->find('debug');
        $buffer .= $this->sectionA626739711c0c0d768d64f9b5fe8a626($context, $indent, $value);
        $buffer .= $indent . '        </div>
';
        $buffer .= $indent . '    </div>
';
        $buffer .= $indent . '</footer>
';
        $buffer .= $indent . '<div id="back-to-top"><i class="fa fa-angle-up "></i></div>
';
        $value = $context->find('savediscard');
        $buffer .= $this->section3c27a20a3b534baaec2c0288b92ebf93($context, $indent, $value);
        $buffer .= '
';
        $buffer .= $indent . '
';
        $buffer .= $indent . '</div>';
        $buffer .= '
';
        $buffer .= $indent . '</div>';
        $buffer .= '
';
        $buffer .= $indent . '
';
        $value = $this->resolveValue($context->findDot('output.standard_end_of_body_html'), $context);
        $buffer .= $indent . ($value === null ? '' : $value);
        $buffer .= '
';
        $buffer .= $indent . '
';
        $value = $context->find('customjsfiles');
        $buffer .= $this->section87ae0ddfcd65d7157eb7deb60f58a7b2($context, $indent, $value);
        $value = $context->find('customjs');
        $buffer .= $this->section1932cbbc590e4192c281231e589d6010($context, $indent, $value);
        $buffer .= $indent . '
';
        $value = $this->resolveValue($context->findDot('output.get_all_tracking_methods'), $context);
        $buffer .= $indent . ($value === null ? '' : $value);
        $buffer .= '
';
        $buffer .= $indent . '
';
        $buffer .= $indent . '</body>
';
        $buffer .= $indent . '</html>
';
        $value = $context->find('js');
        $buffer .= $this->sectionBb7da187201deca5f3da70507c0f4e6b($context, $indent, $value);

        return $buffer;
    }

    private function section501bdf5dd075de7912c4d5eb82e5baca(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '{{{output.get_footer_blocks}}}';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $value = $this->resolveValue($context->findDot('output.get_footer_blocks'), $context);
                $buffer .= ($value === null ? '' : $value);
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section4357478ca66826598b1f3b2bf3307f11(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
    <div class="container">
        <div class="row">
            <div class="col-12 pagination-centered socialicons">
            {{{socialicons}}}
            </div>
        </div>
    </div>
    ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= $indent . '    <div class="container">
';
                $buffer .= $indent . '        <div class="row">
';
                $buffer .= $indent . '            <div class="col-12 pagination-centered socialicons">
';
                $buffer .= $indent . '            ';
                $value = $this->resolveValue($context->find('socialicons'), $context);
                $buffer .= ($value === null ? '' : $value);
                $buffer .= '
';
                $buffer .= $indent . '            </div>
';
                $buffer .= $indent . '        </div>
';
                $buffer .= $indent . '    </div>
';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section5fcc922de49e50853a1bcab7a70769a8(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = 'col-md-4';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= 'col-md-4';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function sectionB021f3ab1b4994285664fe2250f3b959(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
                <div class="{{#moodledocs}}col-md-4{{/moodledocs}}{{^moodledocs}}col-md-8{{/moodledocs}} my-md-0 my-2">{{{footnote}}}</div>
                ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= $indent . '                <div class="';
                $value = $context->find('moodledocs');
                $buffer .= $this->section5fcc922de49e50853a1bcab7a70769a8($context, $indent, $value);
                $value = $context->find('moodledocs');
                if (empty($value)) {
                    
                    $buffer .= 'col-md-8';
                }
                $buffer .= ' my-md-0 my-2">';
                $value = $this->resolveValue($context->find('footnote'), $context);
                $buffer .= ($value === null ? '' : $value);
                $buffer .= '</div>
';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section5e1dd2c815b01a50ebb19be0d0193d4f(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
                <div class="col-md-4 my-md-0 my-2 helplink">{{{output.page_doc_link}}}</div>
                ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= $indent . '                <div class="col-md-4 my-md-0 my-2 helplink">';
                $value = $this->resolveValue($context->findDot('output.page_doc_link'), $context);
                $buffer .= ($value === null ? '' : $value);
                $buffer .= '</div>
';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function sectionA626739711c0c0d768d64f9b5fe8a626(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
            <div class="row">
                <div class="col-12 my-md-0 my-2">
                    {{{debug}}}
                </div>
            </div>
            ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= $indent . '            <div class="row">
';
                $buffer .= $indent . '                <div class="col-12 my-md-0 my-2">
';
                $buffer .= $indent . '                    ';
                $value = $this->resolveValue($context->find('debug'), $context);
                $buffer .= ($value === null ? '' : $value);
                $buffer .= '
';
                $buffer .= $indent . '                </div>
';
                $buffer .= $indent . '            </div>
';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section3c27a20a3b534baaec2c0288b92ebf93(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '{{{savediscard}}}';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $value = $this->resolveValue($context->find('savediscard'), $context);
                $buffer .= $indent . ($value === null ? '' : $value);
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section87ae0ddfcd65d7157eb7deb60f58a7b2(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
<script src="{{.}}"></script>
';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= $indent . '<script src="';
                $value = $this->resolveValue($context->last(), $context);
                $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
                $buffer .= '"></script>
';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section1470c6530559cd2f4459c29211fe6454(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
{{{customjs}}}
';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $value = $this->resolveValue($context->find('customjs'), $context);
                $buffer .= $indent . ($value === null ? '' : $value);
                $buffer .= '
';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section1932cbbc590e4192c281231e589d6010(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
{{#js}}
{{{customjs}}}
{{/js}}
';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $value = $context->find('js');
                $buffer .= $this->section1470c6530559cd2f4459c29211fe6454($context, $indent, $value);
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function sectionBb7da187201deca5f3da70507c0f4e6b(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
    M.util.js_pending(\'theme_boost/loader\');
    require([\'theme_boost/loader\', \'theme_boost/drawer\'], function(Loader, Drawer) {
        Drawer.init();
        M.util.js_complete(\'theme_boost/loader\');
    });
';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= $indent . '    M.util.js_pending(\'theme_boost/loader\');
';
                $buffer .= $indent . '    require([\'theme_boost/loader\', \'theme_boost/drawer\'], function(Loader, Drawer) {
';
                $buffer .= $indent . '        Drawer.init();
';
                $buffer .= $indent . '        M.util.js_complete(\'theme_boost/loader\');
';
                $buffer .= $indent . '    });
';
                $context->pop();
            }
        }
    
        return $buffer;
    }

}
