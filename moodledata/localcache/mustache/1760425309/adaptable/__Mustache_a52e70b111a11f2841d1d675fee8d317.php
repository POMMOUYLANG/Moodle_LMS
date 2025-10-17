<?php

class __Mustache_a52e70b111a11f2841d1d675fee8d317 extends Mustache_Template
{
    private $lambdaHelper;

    public function renderInternal(Mustache_Context $context, $indent = '')
    {
        $this->lambdaHelper = new Mustache_LambdaHelper($this->mustache, $context);
        $buffer = '';

        $buffer .= $indent . '<div id="course-format-';
        $value = $this->resolveValue($context->find('uniqid'), $context);
        $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
        $buffer .= '">
';
        $buffer .= $indent . '    <h2 class="accesshide">';
        $value = $this->resolveValue($context->find('title'), $context);
        $buffer .= ($value === null ? '' : $value);
        $buffer .= '</h2>
';
        $buffer .= $indent . '    ';
        $value = $this->resolveValue($context->find('completionhelp'), $context);
        $buffer .= ($value === null ? '' : $value);
        $buffer .= '
';
        $buffer .= $indent . '    <ul class="';
        $value = $this->resolveValue($context->find('format'), $context);
        $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
        $buffer .= ' section-list" data-for="course_sectionlist">
';
        $value = $context->find('initialsection');
        $buffer .= $this->section8827b1a17576f48cfc178a23d2836e73($context, $indent, $value);
        $value = $context->find('sections');
        $buffer .= $this->section8827b1a17576f48cfc178a23d2836e73($context, $indent, $value);
        $buffer .= $indent . '    </ul>
';
        $value = $context->find('hasnavigation');
        $buffer .= $this->section599ca8e4d6a882f4d9098f88d2f629d6($context, $indent, $value);
        $value = $context->find('numsections');
        $buffer .= $this->sectionAa684381f58ae44f806f4400587dc145($context, $indent, $value);
        $value = $context->find('bulkedittools');
        $buffer .= $this->section5274b515c81740c56f6244e619ac02c6($context, $indent, $value);
        $buffer .= $indent . '</div>
';
        $value = $context->find('js');
        $buffer .= $this->sectionAa71527d87c91cc4ef9ee8646559d83a($context, $indent, $value);

        return $buffer;
    }

    private function section8827b1a17576f48cfc178a23d2836e73(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
            {{$ core_courseformat/local/content/section }}
                {{> core_courseformat/local/content/section }}
            {{/ core_courseformat/local/content/section }}
        ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $blockFunction = $context->findInBlock('core_courseformat/local/content/section');
                if (is_callable($blockFunction)) {
                    $buffer .= call_user_func($blockFunction, $context);
                } else {
                    if ($partial = $this->mustache->loadPartial('core_courseformat/local/content/section')) {
                        $buffer .= $partial->renderInternal($context, $indent . '                ');
                    }
                }
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function sectionFacbfac0606048ebfae74ceeb14690de(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
            {{$ core_courseformat/local/content/sectionnavigation }}
                {{> core_courseformat/local/content/sectionnavigation }}
            {{/ core_courseformat/local/content/sectionnavigation }}
        ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $blockFunction = $context->findInBlock('core_courseformat/local/content/sectionnavigation');
                if (is_callable($blockFunction)) {
                    $buffer .= call_user_func($blockFunction, $context);
                } else {
                    if ($partial = $this->mustache->loadPartial('core_courseformat/local/content/sectionnavigation')) {
                        $buffer .= $partial->renderInternal($context, $indent . '                ');
                    }
                }
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section986e0d757a08f2dc36e0f3ce06fcab6e(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
            {{$ core_courseformat/local/content/sectionselector }}
                {{> core_courseformat/local/content/sectionselector }}
            {{/ core_courseformat/local/content/sectionselector }}
        ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $blockFunction = $context->findInBlock('core_courseformat/local/content/sectionselector');
                if (is_callable($blockFunction)) {
                    $buffer .= call_user_func($blockFunction, $context);
                } else {
                    if ($partial = $this->mustache->loadPartial('core_courseformat/local/content/sectionselector')) {
                        $buffer .= $partial->renderInternal($context, $indent . '                ');
                    }
                }
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section599ca8e4d6a882f4d9098f88d2f629d6(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
    <div class="single-section">
        {{#sectionnavigation}}
            {{$ core_courseformat/local/content/sectionnavigation }}
                {{> core_courseformat/local/content/sectionnavigation }}
            {{/ core_courseformat/local/content/sectionnavigation }}
        {{/sectionnavigation}}
        <ul class="{{format}} section-list">
        {{#singlesection}}
            {{$ core_courseformat/local/content/section }}
                {{> core_courseformat/local/content/section }}
            {{/ core_courseformat/local/content/section }}
        {{/singlesection}}
        </ul>
        {{#sectionselector}}
            {{$ core_courseformat/local/content/sectionselector }}
                {{> core_courseformat/local/content/sectionselector }}
            {{/ core_courseformat/local/content/sectionselector }}
        {{/sectionselector}}
    </div>
    ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= $indent . '    <div class="single-section">
';
                $value = $context->find('sectionnavigation');
                $buffer .= $this->sectionFacbfac0606048ebfae74ceeb14690de($context, $indent, $value);
                $buffer .= $indent . '        <ul class="';
                $value = $this->resolveValue($context->find('format'), $context);
                $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
                $buffer .= ' section-list">
';
                $value = $context->find('singlesection');
                $buffer .= $this->section8827b1a17576f48cfc178a23d2836e73($context, $indent, $value);
                $buffer .= $indent . '        </ul>
';
                $value = $context->find('sectionselector');
                $buffer .= $this->section986e0d757a08f2dc36e0f3ce06fcab6e($context, $indent, $value);
                $buffer .= $indent . '    </div>
';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function sectionAa684381f58ae44f806f4400587dc145(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
        {{^singlesection}}
            {{$ core_courseformat/local/content/addsection}}
                {{> core_courseformat/local/content/addsection}}
            {{/ core_courseformat/local/content/addsection}}
        {{/singlesection}}
    ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $value = $context->find('singlesection');
                if (empty($value)) {
                    
                    $blockFunction = $context->findInBlock('core_courseformat/local/content/addsection');
                    if (is_callable($blockFunction)) {
                        $buffer .= call_user_func($blockFunction, $context);
                    } else {
                        if ($partial = $this->mustache->loadPartial('core_courseformat/local/content/addsection')) {
                            $buffer .= $partial->renderInternal($context, $indent . '                ');
                        }
                    }
                }
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section5274b515c81740c56f6244e619ac02c6(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
        {{$ core_courseformat/local/content/bulkedittools}}
            {{> core_courseformat/local/content/bulkedittools}}
        {{/ core_courseformat/local/content/bulkedittools}}
    ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $blockFunction = $context->findInBlock('core_courseformat/local/content/bulkedittools');
                if (is_callable($blockFunction)) {
                    $buffer .= call_user_func($blockFunction, $context);
                } else {
                    if ($partial = $this->mustache->loadPartial('core_courseformat/local/content/bulkedittools')) {
                        $buffer .= $partial->renderInternal($context, $indent . '            ');
                    }
                }
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function sectionAa71527d87c91cc4ef9ee8646559d83a(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
require([\'core_courseformat/local/content\'], function(component) {
    component.init(\'#page\', {}, {{sectionreturn}});
});
';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= $indent . 'require([\'core_courseformat/local/content\'], function(component) {
';
                $buffer .= $indent . '    component.init(\'#page\', {}, ';
                $value = $this->resolveValue($context->find('sectionreturn'), $context);
                $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
                $buffer .= ');
';
                $buffer .= $indent . '});
';
                $context->pop();
            }
        }
    
        return $buffer;
    }

}
