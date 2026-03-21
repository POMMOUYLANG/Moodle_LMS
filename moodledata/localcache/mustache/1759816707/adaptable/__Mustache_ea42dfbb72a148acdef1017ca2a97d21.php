<?php

class __Mustache_ea42dfbb72a148acdef1017ca2a97d21 extends Mustache_Template
{
    private $lambdaHelper;

    public function renderInternal(Mustache_Context $context, $indent = '')
    {
        $this->lambdaHelper = new Mustache_LambdaHelper($this->mustache, $context);
        $buffer = '';

        $buffer .= $indent . '<h2 class="login-heading">';
        $value = $context->find('str');
        $buffer .= $this->section93e4b62aaf677bf7878b06c5ac540671($context, $indent, $value);
        $buffer .= '</h2>
';
        $buffer .= $indent . '<form action="';
        $value = $this->resolveValue($context->find('loginurl'), $context);
        $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
        $buffer .= '" method="post" id="guestlogin">
';
        $buffer .= $indent . '    <input type="hidden" name="logintoken" value="';
        $value = $this->resolveValue($context->find('logintoken'), $context);
        $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
        $buffer .= '">
';
        $buffer .= $indent . '    <input type="hidden" name="username" value="guest">
';
        $buffer .= $indent . '    <input type="hidden" name="password" value="guest">
';
        $buffer .= $indent . '    <button class="loginbtn btn btn-secondary btn-block" type="submit">';
        $value = $context->find('str');
        $buffer .= $this->section017c9686023b74877131737c59ff1162($context, $indent, $value);
        $buffer .= '</button>
';
        $buffer .= $indent . '</form>';

        return $buffer;
    }

    private function section93e4b62aaf677bf7878b06c5ac540671(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = 'someallowguest';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= 'someallowguest';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section017c9686023b74877131737c59ff1162(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = 'loginguest';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= 'loginguest';
                $context->pop();
            }
        }
    
        return $buffer;
    }

}
