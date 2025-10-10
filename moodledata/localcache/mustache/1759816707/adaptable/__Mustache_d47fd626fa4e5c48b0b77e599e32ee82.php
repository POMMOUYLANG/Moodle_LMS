<?php

class __Mustache_d47fd626fa4e5c48b0b77e599e32ee82 extends Mustache_Template
{
    private $lambdaHelper;

    public function renderInternal(Mustache_Context $context, $indent = '')
    {
        $this->lambdaHelper = new Mustache_LambdaHelper($this->mustache, $context);
        $buffer = '';

        $value = $context->find('modulename');
        $buffer .= $this->section536288848cebe04fef22ce44ddf63b19($context, $indent, $value);
        $value = $context->find('modulename');
        if (empty($value)) {
            
            $buffer .= $indent . '    ';
            $value = $context->find('icon');
            $buffer .= $this->section61e67d431a51217cfb9d099db34d2445($context, $indent, $value);
            $buffer .= '
';
        }

        return $buffer;
    }

    private function sectionB71270f52aa9670ce1d7ea63cf6e6aab(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
           <img alt="{{alttext}}" title="{{alttext}}" src="{{{ iconurl }}}" class="icon {{iconclass}}">
        ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= $indent . '           <img alt="';
                $value = $this->resolveValue($context->find('alttext'), $context);
                $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
                $buffer .= '" title="';
                $value = $this->resolveValue($context->find('alttext'), $context);
                $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
                $buffer .= '" src="';
                $value = $this->resolveValue($context->find('iconurl'), $context);
                $buffer .= ($value === null ? '' : $value);
                $buffer .= '" class="icon ';
                $value = $this->resolveValue($context->find('iconclass'), $context);
                $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
                $buffer .= '">
';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section59b2e3465b84557dc496d02caadc22e7(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = ' monologo, {{modulename}} ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= ' monologo, ';
                $value = $this->resolveValue($context->find('modulename'), $context);
                $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
                $buffer .= ' ';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section75ffa6dc234e24a88c518262db58c87f(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
        {{#iconurl}}
           <img alt="{{alttext}}" title="{{alttext}}" src="{{{ iconurl }}}" class="icon {{iconclass}}">
        {{/iconurl}}
        {{^iconurl}}
            {{#pix}} monologo, {{modulename}} {{/pix}}
        {{/iconurl}}
    ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $value = $context->find('iconurl');
                $buffer .= $this->sectionB71270f52aa9670ce1d7ea63cf6e6aab($context, $indent, $value);
                $value = $context->find('iconurl');
                if (empty($value)) {
                    
                    $buffer .= $indent . '            ';
                    $value = $context->find('pix');
                    $buffer .= $this->section59b2e3465b84557dc496d02caadc22e7($context, $indent, $value);
                    $buffer .= '
';
                }
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section536288848cebe04fef22ce44ddf63b19(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
    {{#icon}}
        {{#iconurl}}
           <img alt="{{alttext}}" title="{{alttext}}" src="{{{ iconurl }}}" class="icon {{iconclass}}">
        {{/iconurl}}
        {{^iconurl}}
            {{#pix}} monologo, {{modulename}} {{/pix}}
        {{/iconurl}}
    {{/icon}}
    {{^icon}}
       {{#pix}} monologo, {{modulename}} {{/pix}}
    {{/icon}}
';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $value = $context->find('icon');
                $buffer .= $this->section75ffa6dc234e24a88c518262db58c87f($context, $indent, $value);
                $value = $context->find('icon');
                if (empty($value)) {
                    
                    $buffer .= $indent . '       ';
                    $value = $context->find('pix');
                    $buffer .= $this->section59b2e3465b84557dc496d02caadc22e7($context, $indent, $value);
                    $buffer .= '
';
                }
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section17144e6457bffc9fa0437cc7e3d39509(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = ' {{key}}, {{component}}, {{alttext}} ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= ' ';
                $value = $this->resolveValue($context->find('key'), $context);
                $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
                $buffer .= ', ';
                $value = $this->resolveValue($context->find('component'), $context);
                $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
                $buffer .= ', ';
                $value = $this->resolveValue($context->find('alttext'), $context);
                $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
                $buffer .= ' ';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section61e67d431a51217cfb9d099db34d2445(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '{{#pix}} {{key}}, {{component}}, {{alttext}} {{/pix}}';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $value = $context->find('pix');
                $buffer .= $this->section17144e6457bffc9fa0437cc7e3d39509($context, $indent, $value);
                $context->pop();
            }
        }
    
        return $buffer;
    }

}
