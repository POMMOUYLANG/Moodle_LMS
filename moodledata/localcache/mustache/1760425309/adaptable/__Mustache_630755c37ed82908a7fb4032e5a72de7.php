<?php

class __Mustache_630755c37ed82908a7fb4032e5a72de7 extends Mustache_Template
{
    private $lambdaHelper;

    public function renderInternal(Mustache_Context $context, $indent = '')
    {
        $this->lambdaHelper = new Mustache_LambdaHelper($this->mustache, $context);
        $buffer = '';

        $value = $context->find('link');
        $buffer .= $this->section58d3595260a015bacf5a2e25261f596f($context, $indent, $value);
        $buffer .= $indent . '    <span
';
        $buffer .= $indent . '        class="date ';
        $value = $context->find('ispast');
        $buffer .= $this->sectionE8baf066040de97ee7d38588810d569b($context, $indent, $value);
        $buffer .= ' ';
        $value = $context->find('isnear');
        $buffer .= $this->section446fce781713c1edca78632b52d11dbb($context, $indent, $value);
        $buffer .= '"
';
        $buffer .= $indent . '        data-timestamp="';
        $value = $this->resolveValue($context->find('timestamp'), $context);
        $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
        $buffer .= '"
';
        $buffer .= $indent . '        ';
        $value = $context->find('needtitle');
        $buffer .= $this->section1c69f6fa0464f65d0c6d316d60c6a8e4($context, $indent, $value);
        $buffer .= '
';
        $buffer .= $indent . '    >
';
        $value = $context->find('nearicon');
        $buffer .= $this->section949c084b88932f73d980a654c29ef322($context, $indent, $value);
        $buffer .= $indent . '        ';
        $value = $context->find('date');
        $buffer .= $this->section6a5ca4d64b0227995fa7acbc7d27048c($context, $indent, $value);
        $value = $this->resolveValue($context->find('time'), $context);
        $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
        $buffer .= '
';
        $buffer .= $indent . '    </span>
';
        $value = $context->find('link');
        $buffer .= $this->section75a2119ed40949229fd41ece0b11405f($context, $indent, $value);

        return $buffer;
    }

    private function section58d3595260a015bacf5a2e25261f596f(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
<a href="{{link}}" title="{{userdate}}">
';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= $indent . '<a href="';
                $value = $this->resolveValue($context->find('link'), $context);
                $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
                $buffer .= '" title="';
                $value = $this->resolveValue($context->find('userdate'), $context);
                $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
                $buffer .= '">
';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function sectionE8baf066040de97ee7d38588810d569b(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = 'dimmed_text';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= 'dimmed_text';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section446fce781713c1edca78632b52d11dbb(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = 'text-danger';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= 'text-danger';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section1c69f6fa0464f65d0c6d316d60c6a8e4(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = ' title="{{userdate}}" ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= ' title="';
                $value = $this->resolveValue($context->find('userdate'), $context);
                $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
                $buffer .= '" ';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section949c084b88932f73d980a654c29ef322(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
            {{>core/pix_icon}}
        ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                if ($partial = $this->mustache->loadPartial('core/pix_icon')) {
                    $buffer .= $partial->renderInternal($context, $indent . '            ');
                }
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section7a1c7a2f224d8779612b2d8b33f6ac44(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = ', ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= ', ';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section6a5ca4d64b0227995fa7acbc7d27048c(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '{{date}}{{#time}}, {{/time}}';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $value = $this->resolveValue($context->find('date'), $context);
                $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
                $value = $context->find('time');
                $buffer .= $this->section7a1c7a2f224d8779612b2d8b33f6ac44($context, $indent, $value);
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section75a2119ed40949229fd41ece0b11405f(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
</a>
';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= $indent . '</a>
';
                $context->pop();
            }
        }
    
        return $buffer;
    }

}
