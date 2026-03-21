<?php

class __Mustache_225bff775846b14c7160d063867fa2db extends Mustache_Template
{
    private $lambdaHelper;

    public function renderInternal(Mustache_Context $context, $indent = '')
    {
        $this->lambdaHelper = new Mustache_LambdaHelper($this->mustache, $context);
        $buffer = '';

        $value = $context->find('courseindex');
        $buffer .= $this->section521eca0485f4e1341ef0d5f92c6fed39($context, $indent, $value);

        return $buffer;
    }

    private function section6f33152a41341e2c397de871a1796b75(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = 'right';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= 'right';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section0aa6fe3b3c41579e49bb7bcc3c6a53a1(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = 'left';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= 'left';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function sectionAfaaa3dab86fd46a075d917e3ce891f0(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = 'opendrawerindex, core';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= 'opendrawerindex, core';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section3feaf801d4aa0a811fe41c3427e53335(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = ' t/index_drawer, moodle ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= ' t/index_drawer, moodle ';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section521eca0485f4e1341ef0d5f92c6fed39(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
<div class="drawer-toggles d-flex">
    <div class="drawer-toggler drawer-{{#left}}right{{/left}}{{^left}}left{{/left}}-toggle open-nav d-print-none">
        <button
            class="btn icon-no-margin"
            data-toggler="drawers"
            data-action="toggle"
            data-target="theme_adaptable-drawers-courseindex"
            data-bs-toggle="tooltip"
            data-bs-placement="{{#left}}left{{/left}}{{^left}}right{{/left}}"
            title="{{#str}}opendrawerindex, core{{/str}}"
        >
        <span class="visually-hidden">{{#str}}opendrawerindex, core{{/str}}</span>
            {{#pix}} t/index_drawer, moodle {{/pix}}
        </button>
    </div>
</div>
';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= $indent . '<div class="drawer-toggles d-flex">
';
                $buffer .= $indent . '    <div class="drawer-toggler drawer-';
                $value = $context->find('left');
                $buffer .= $this->section6f33152a41341e2c397de871a1796b75($context, $indent, $value);
                $value = $context->find('left');
                if (empty($value)) {
                    
                    $buffer .= 'left';
                }
                $buffer .= '-toggle open-nav d-print-none">
';
                $buffer .= $indent . '        <button
';
                $buffer .= $indent . '            class="btn icon-no-margin"
';
                $buffer .= $indent . '            data-toggler="drawers"
';
                $buffer .= $indent . '            data-action="toggle"
';
                $buffer .= $indent . '            data-target="theme_adaptable-drawers-courseindex"
';
                $buffer .= $indent . '            data-bs-toggle="tooltip"
';
                $buffer .= $indent . '            data-bs-placement="';
                $value = $context->find('left');
                $buffer .= $this->section0aa6fe3b3c41579e49bb7bcc3c6a53a1($context, $indent, $value);
                $value = $context->find('left');
                if (empty($value)) {
                    
                    $buffer .= 'right';
                }
                $buffer .= '"
';
                $buffer .= $indent . '            title="';
                $value = $context->find('str');
                $buffer .= $this->sectionAfaaa3dab86fd46a075d917e3ce891f0($context, $indent, $value);
                $buffer .= '"
';
                $buffer .= $indent . '        >
';
                $buffer .= $indent . '        <span class="visually-hidden">';
                $value = $context->find('str');
                $buffer .= $this->sectionAfaaa3dab86fd46a075d917e3ce891f0($context, $indent, $value);
                $buffer .= '</span>
';
                $buffer .= $indent . '            ';
                $value = $context->find('pix');
                $buffer .= $this->section3feaf801d4aa0a811fe41c3427e53335($context, $indent, $value);
                $buffer .= '
';
                $buffer .= $indent . '        </button>
';
                $buffer .= $indent . '    </div>
';
                $buffer .= $indent . '</div>
';
                $context->pop();
            }
        }
    
        return $buffer;
    }

}
