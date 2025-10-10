<?php

class __Mustache_c54e92d18fe4f0e5abb589bd0e2af0a2 extends Mustache_Template
{
    private $lambdaHelper;

    public function renderInternal(Mustache_Context $context, $indent = '')
    {
        $this->lambdaHelper = new Mustache_LambdaHelper($this->mustache, $context);
        $buffer = '';

        $buffer .= $indent . '
';
        $buffer .= $indent . '<div id="savediscardsection" style="margin-top: ';
        $value = $this->resolveValue($context->find('topmargin'), $context);
        $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
        $buffer .= ';">
';
        $buffer .= $indent . '<input id="adminresetbutton" class="form-reset" type="reset" form="adminsettings" value="';
        $value = $context->find('str');
        $buffer .= $this->section36072350c9ef9b8da6afd65daca5f381($context, $indent, $value);
        $buffer .= '" data-confirm="';
        $value = $context->find('str');
        $buffer .= $this->sectionDcdfaf9cbe3510115fd35db614aa027e($context, $indent, $value);
        $buffer .= '" />
';
        $buffer .= $indent . '<input id="adminsubmitbutton" class="form-submit" type="submit" form="adminsettings" value="';
        $value = $context->find('str');
        $buffer .= $this->section099f7f8d2f8aed8c01dfd316ee6b89ae($context, $indent, $value);
        $buffer .= '" />
';
        $buffer .= $indent . '</div>
';
        $value = $context->find('js');
        $buffer .= $this->sectionC71d103114aa93cf35fe1c6d643ff356($context, $indent, $value);

        return $buffer;
    }

    private function section36072350c9ef9b8da6afd65daca5f381(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = 'discardbuttontext, theme_adaptable';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= 'discardbuttontext, theme_adaptable';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function sectionDcdfaf9cbe3510115fd35db614aa027e(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = 'enablesavecanceloverlayresetconfirm, theme_adaptable';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= 'enablesavecanceloverlayresetconfirm, theme_adaptable';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section099f7f8d2f8aed8c01dfd316ee6b89ae(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = 'savebuttontext, theme_adaptable';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= 'savebuttontext, theme_adaptable';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function sectionC71d103114aa93cf35fe1c6d643ff356(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
require([\'theme_adaptable/savebutton\'], function(mod) {
    mod.saveButtonInit();
});
';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= $indent . 'require([\'theme_adaptable/savebutton\'], function(mod) {
';
                $buffer .= $indent . '    mod.saveButtonInit();
';
                $buffer .= $indent . '});
';
                $context->pop();
            }
        }
    
        return $buffer;
    }

}
