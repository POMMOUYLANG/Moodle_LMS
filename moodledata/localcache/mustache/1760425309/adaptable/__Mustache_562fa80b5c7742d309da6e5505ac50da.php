<?php

class __Mustache_562fa80b5c7742d309da6e5505ac50da extends Mustache_Template
{
    private $lambdaHelper;

    public function renderInternal(Mustache_Context $context, $indent = '')
    {
        $this->lambdaHelper = new Mustache_LambdaHelper($this->mustache, $context);
        $buffer = '';

        $buffer .= $indent . '<h3>';
        $value = $context->find('str');
        $buffer .= $this->section2451981983c7efa3ffd74cea9b3dadd1($context, $indent, $value);
        $buffer .= '</h3>
';
        $buffer .= $indent . '
';
        $buffer .= $indent . '<ul>
';
        $value = $context->find('files');
        $buffer .= $this->section78d2a3769ab56859151f28d99f3c4f34($context, $indent, $value);
        $buffer .= $indent . '</ul>
';

        return $buffer;
    }

    private function section2451981983c7efa3ffd74cea9b3dadd1(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = 'file, assignsubmission_file';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= 'file, assignsubmission_file';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section3174eb73177fd656f27a0d95efca0f47(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '{{filepath}}';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $value = $this->resolveValue($context->find('filepath'), $context);
                $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section84fd497a3a715cb844b1f3ad229a2a7a(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '{{filesize}}';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $value = $this->resolveValue($context->find('filesize'), $context);
                $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section002e5b7a8798f5a1898266a73f5e7de8(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '{{coursename}}';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $value = $this->resolveValue($context->find('coursename'), $context);
                $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section2d54b09df2e4700133bf9265a3cda509(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = 'filewithsize, assignsubmission_file, {"filename": {{#quote}}{{filepath}}{{/quote}}, "size": {{#quote}}{{filesize}}{{/quote}}, "coursename": {{#quote}}{{coursename}}{{/quote}} } ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= 'filewithsize, assignsubmission_file, {"filename": ';
                $value = $context->find('quote');
                $buffer .= $this->section3174eb73177fd656f27a0d95efca0f47($context, $indent, $value);
                $buffer .= ', "size": ';
                $value = $context->find('quote');
                $buffer .= $this->section84fd497a3a715cb844b1f3ad229a2a7a($context, $indent, $value);
                $buffer .= ', "coursename": ';
                $value = $context->find('quote');
                $buffer .= $this->section002e5b7a8798f5a1898266a73f5e7de8($context, $indent, $value);
                $buffer .= ' } ';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section78d2a3769ab56859151f28d99f3c4f34(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
    <li>{{#str}}filewithsize, assignsubmission_file, {"filename": {{#quote}}{{filepath}}{{/quote}}, "size": {{#quote}}{{filesize}}{{/quote}}, "coursename": {{#quote}}{{coursename}}{{/quote}} } {{/str}}</li>
';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= $indent . '    <li>';
                $value = $context->find('str');
                $buffer .= $this->section2d54b09df2e4700133bf9265a3cda509($context, $indent, $value);
                $buffer .= '</li>
';
                $context->pop();
            }
        }
    
        return $buffer;
    }

}
