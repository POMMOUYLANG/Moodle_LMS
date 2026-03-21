<?php

class __Mustache_df6e2979e99e4d02e455c3ad93c93a68 extends Mustache_Template
{
    private $lambdaHelper;

    public function renderInternal(Mustache_Context $context, $indent = '')
    {
        $this->lambdaHelper = new Mustache_LambdaHelper($this->mustache, $context);
        $buffer = '';

        $buffer .= $indent . '<div class="drawer-toggles d-flex">
';
        $value = $context->find('hassidepost');
        $buffer .= $this->section4fbfce766da893e6b7329848288fda02($context, $indent, $value);
        $buffer .= $indent . '</div>
';

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

    private function section245ba37b5a58f162a63e0d10700eb739(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = 'opendrawerblocks, core';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= 'opendrawerblocks, core';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section79da40c1066e2cf225d96d0ac15e9d3d(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = 'ltr';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= 'ltr';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section0bf870995ed49a0bb4a766ed18e0f142(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = 't/blocks_drawer, core';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= 't/blocks_drawer, core';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function sectionC3134757a89352fe1e84f10766ef1182(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = 'rtl';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= 'rtl';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function sectionD277bad9387573c58f79f77fc705e8dd(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = 't/blocks_drawer_rtl, core';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= 't/blocks_drawer_rtl, core';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section4fbfce766da893e6b7329848288fda02(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
    <div class="drawer-toggler drawer-{{#left}}left{{/left}}{{^left}}right{{/left}}-toggle ms-auto d-print-none">
        <button
            class="btn icon-no-margin"
            data-toggler="drawers"
            data-action="toggle"
            data-target="theme_adaptable-drawers-sidepost"
            data-bs-toggle="tooltip"
            data-bs-placement="{{#left}}left{{/left}}{{^left}}right{{/left}}"
            title="{{#str}}opendrawerblocks, core{{/str}}"
        >
            <span class="visually-hidden">{{#str}}opendrawerblocks, core{{/str}}</span>
            <span class="dir-{{#left}}ltr{{/left}}{{^left}}rtl{{/left}}-hide">{{#pix}}t/blocks_drawer, core{{/pix}}</span>
            <span class="dir-{{#left}}rtl{{/left}}{{^left}}ltr{{/left}}-hide">{{#pix}}t/blocks_drawer_rtl, core{{/pix}}</span>
        </button>
    </div>
    ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= $indent . '    <div class="drawer-toggler drawer-';
                $value = $context->find('left');
                $buffer .= $this->section0aa6fe3b3c41579e49bb7bcc3c6a53a1($context, $indent, $value);
                $value = $context->find('left');
                if (empty($value)) {
                    
                    $buffer .= 'right';
                }
                $buffer .= '-toggle ms-auto d-print-none">
';
                $buffer .= $indent . '        <button
';
                $buffer .= $indent . '            class="btn icon-no-margin"
';
                $buffer .= $indent . '            data-toggler="drawers"
';
                $buffer .= $indent . '            data-action="toggle"
';
                $buffer .= $indent . '            data-target="theme_adaptable-drawers-sidepost"
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
                $buffer .= $this->section245ba37b5a58f162a63e0d10700eb739($context, $indent, $value);
                $buffer .= '"
';
                $buffer .= $indent . '        >
';
                $buffer .= $indent . '            <span class="visually-hidden">';
                $value = $context->find('str');
                $buffer .= $this->section245ba37b5a58f162a63e0d10700eb739($context, $indent, $value);
                $buffer .= '</span>
';
                $buffer .= $indent . '            <span class="dir-';
                $value = $context->find('left');
                $buffer .= $this->section79da40c1066e2cf225d96d0ac15e9d3d($context, $indent, $value);
                $value = $context->find('left');
                if (empty($value)) {
                    
                    $buffer .= 'rtl';
                }
                $buffer .= '-hide">';
                $value = $context->find('pix');
                $buffer .= $this->section0bf870995ed49a0bb4a766ed18e0f142($context, $indent, $value);
                $buffer .= '</span>
';
                $buffer .= $indent . '            <span class="dir-';
                $value = $context->find('left');
                $buffer .= $this->sectionC3134757a89352fe1e84f10766ef1182($context, $indent, $value);
                $value = $context->find('left');
                if (empty($value)) {
                    
                    $buffer .= 'ltr';
                }
                $buffer .= '-hide">';
                $value = $context->find('pix');
                $buffer .= $this->sectionD277bad9387573c58f79f77fc705e8dd($context, $indent, $value);
                $buffer .= '</span>
';
                $buffer .= $indent . '        </button>
';
                $buffer .= $indent . '    </div>
';
                $context->pop();
            }
        }
    
        return $buffer;
    }

}
