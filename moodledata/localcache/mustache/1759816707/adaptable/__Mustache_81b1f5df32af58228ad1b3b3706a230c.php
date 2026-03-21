<?php

class __Mustache_81b1f5df32af58228ad1b3b3706a230c extends Mustache_Template
{
    private $lambdaHelper;

    public function renderInternal(Mustache_Context $context, $indent = '')
    {
        $this->lambdaHelper = new Mustache_LambdaHelper($this->mustache, $context);
        $buffer = '';

        $buffer .= $indent . '<div data-region="activity-information" data-activityname="';
        $value = $this->resolveValue($context->find('activityname'), $context);
        $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
        $buffer .= '" class="activity-information">
';
        $buffer .= $indent . '
';
        $value = $context->find('hascompletion');
        $buffer .= $this->section41de233c0e5ffed3347dfcfebeb2e587($context, $indent, $value);
        $buffer .= $indent . '
';
        $value = $context->find('hasdates');
        $buffer .= $this->section7db18de6d5ac6ecf88449a951920953a($context, $indent, $value);
        $buffer .= $indent . '</div>
';

        return $buffer;
    }

    private function sectionB4d533f216f9622c64afd5d00c909083(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = 'completionrequirements, core_course, {{activityname}}';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= 'completionrequirements, core_course, ';
                $value = $this->resolveValue($context->find('activityname'), $context);
                $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function sectionCf0ad3bedba168534198fcab7f03ea8e(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
                            {{> core_course/completion_automatic }}
                        ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                if ($partial = $this->mustache->loadPartial('core_course/completion_automatic')) {
                    $buffer .= $partial->renderInternal($context, $indent . '                            ');
                }
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section62df45aae78574ce097a15a98c28c4f6(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
                    <div class="automatic-completion-conditions" data-region ="completionrequirements" role="list" aria-label="{{#str}}completionrequirements, core_course, {{activityname}}{{/str}}">
                        {{#completiondetails}}
                            {{> core_course/completion_automatic }}
                        {{/completiondetails}}
                    </div>
                ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= $indent . '                    <div class="automatic-completion-conditions" data-region ="completionrequirements" role="list" aria-label="';
                $value = $context->find('str');
                $buffer .= $this->sectionB4d533f216f9622c64afd5d00c909083($context, $indent, $value);
                $buffer .= '">
';
                $value = $context->find('completiondetails');
                $buffer .= $this->sectionCf0ad3bedba168534198fcab7f03ea8e($context, $indent, $value);
                $buffer .= $indent . '                    </div>
';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section92c690780e8a0175b1f6f8492efab3c7(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
                        {{> core_course/completion_manual }}
                    ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                if ($partial = $this->mustache->loadPartial('core_course/completion_manual')) {
                    $buffer .= $partial->renderInternal($context, $indent . '                        ');
                }
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section612574b21d81cb25ba97ab9c2eda5ccf(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
            <div class="completion-info" data-region="completion-info">
                {{#isautomatic}}
                    <div class="automatic-completion-conditions" data-region ="completionrequirements" role="list" aria-label="{{#str}}completionrequirements, core_course, {{activityname}}{{/str}}">
                        {{#completiondetails}}
                            {{> core_course/completion_automatic }}
                        {{/completiondetails}}
                    </div>
                {{/isautomatic}}
                {{^isautomatic}}
                    {{#showmanualcompletion}}
                        {{> core_course/completion_manual }}
                    {{/showmanualcompletion}}
                {{/isautomatic}}
            </div>
        ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= $indent . '            <div class="completion-info" data-region="completion-info">
';
                $value = $context->find('isautomatic');
                $buffer .= $this->section62df45aae78574ce097a15a98c28c4f6($context, $indent, $value);
                $value = $context->find('isautomatic');
                if (empty($value)) {
                    
                    $value = $context->find('showmanualcompletion');
                    $buffer .= $this->section92c690780e8a0175b1f6f8492efab3c7($context, $indent, $value);
                }
                $buffer .= $indent . '            </div>
';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section41de233c0e5ffed3347dfcfebeb2e587(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
        {{#uservisible}}
            <div class="completion-info" data-region="completion-info">
                {{#isautomatic}}
                    <div class="automatic-completion-conditions" data-region ="completionrequirements" role="list" aria-label="{{#str}}completionrequirements, core_course, {{activityname}}{{/str}}">
                        {{#completiondetails}}
                            {{> core_course/completion_automatic }}
                        {{/completiondetails}}
                    </div>
                {{/isautomatic}}
                {{^isautomatic}}
                    {{#showmanualcompletion}}
                        {{> core_course/completion_manual }}
                    {{/showmanualcompletion}}
                {{/isautomatic}}
            </div>
        {{/uservisible}}
    ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $value = $context->find('uservisible');
                $buffer .= $this->section612574b21d81cb25ba97ab9c2eda5ccf($context, $indent, $value);
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section61c11c3e7ba0475f0ab6ec8bcfc78fbc(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
            {{>core_course/activity_date}}
        ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                if ($partial = $this->mustache->loadPartial('core_course/activity_date')) {
                    $buffer .= $partial->renderInternal($context, $indent . '            ');
                }
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section7db18de6d5ac6ecf88449a951920953a(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
    <div data-region="activity-dates" class="activity-dates">
        {{#activitydates}}
            {{>core_course/activity_date}}
        {{/activitydates}}
    </div>
    ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= $indent . '    <div data-region="activity-dates" class="activity-dates">
';
                $value = $context->find('activitydates');
                $buffer .= $this->section61c11c3e7ba0475f0ab6ec8bcfc78fbc($context, $indent, $value);
                $buffer .= $indent . '    </div>
';
                $context->pop();
            }
        }
    
        return $buffer;
    }

}
