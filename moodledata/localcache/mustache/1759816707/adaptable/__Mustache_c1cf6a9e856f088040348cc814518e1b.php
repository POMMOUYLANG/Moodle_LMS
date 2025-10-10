<?php

class __Mustache_c1cf6a9e856f088040348cc814518e1b extends Mustache_Template
{
    private $lambdaHelper;

    public function renderInternal(Mustache_Context $context, $indent = '')
    {
        $this->lambdaHelper = new Mustache_LambdaHelper($this->mustache, $context);
        $buffer = '';

        $buffer .= $indent . '<div class="container-fluid mb-4">
';
        $buffer .= $indent . '    <div class="d-flex flex-wrap gap-2">
';
        $value = $context->find('submit');
        $buffer .= $this->section52603c187f8dd3d65e1d86205613d63a($context, $indent, $value);
        $value = $context->find('previoussubmission');
        $buffer .= $this->section52603c187f8dd3d65e1d86205613d63a($context, $indent, $value);
        $value = $context->find('edit');
        $buffer .= $this->section81054f22bcca4e13e607591708f914bf($context, $indent, $value);
        $value = $context->find('remove');
        $buffer .= $this->sectionFd2d2a372565d58d0d48f15436b0c7bf($context, $indent, $value);
        $buffer .= $indent . '    </div>
';
        $buffer .= $indent . '</div>
';

        return $buffer;
    }

    private function section99d07c9571d5436bf3577ef619a3fbbd(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
                {{>core/single_button}}
            ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                if ($partial = $this->mustache->loadPartial('core/single_button')) {
                    $buffer .= $partial->renderInternal($context, $indent . '                ');
                }
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section05aa320cfaae3196b971e10ee8e657b8(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
                {{>core/help_icon}}
            ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                if ($partial = $this->mustache->loadPartial('core/help_icon')) {
                    $buffer .= $partial->renderInternal($context, $indent . '                ');
                }
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section52603c187f8dd3d65e1d86205613d63a(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
        <div>
            {{#button}}
                {{>core/single_button}}
            {{/button}}
            {{#help}}
                {{>core/help_icon}}
            {{/help}}
        </div>
        ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= $indent . '        <div>
';
                $value = $context->find('button');
                $buffer .= $this->section99d07c9571d5436bf3577ef619a3fbbd($context, $indent, $value);
                $value = $context->find('help');
                $buffer .= $this->section05aa320cfaae3196b971e10ee8e657b8($context, $indent, $value);
                $buffer .= $indent . '        </div>
';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section59da52b69c6eeb90c10dd3e08a9ba266(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
                    {{>core/action_link}}
                ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                if ($partial = $this->mustache->loadPartial('core/action_link')) {
                    $buffer .= $partial->renderInternal($context, $indent . '                    ');
                }
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function sectionE3fdd7a2b146d7ff432f87a81b3e3b36(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
                {{#button}}
                    {{>core/action_link}}
                {{/button}}
            ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $value = $context->find('button');
                $buffer .= $this->section59da52b69c6eeb90c10dd3e08a9ba266($context, $indent, $value);
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function sectionC0a7a31fe8f3d761f2df8d39b4d04247(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
                    {{>core/single_button}}
                ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                if ($partial = $this->mustache->loadPartial('core/single_button')) {
                    $buffer .= $partial->renderInternal($context, $indent . '                    ');
                }
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function sectionE376858cf6101e5b36df1c2ecf1c24a3(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
                    {{>core/help_icon}}
                ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                if ($partial = $this->mustache->loadPartial('core/help_icon')) {
                    $buffer .= $partial->renderInternal($context, $indent . '                    ');
                }
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section81054f22bcca4e13e607591708f914bf(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
        <div>
            {{#begin}}
                {{#button}}
                    {{>core/action_link}}
                {{/button}}
            {{/begin}}
            {{^begin}}
                {{#button}}
                    {{>core/single_button}}
                {{/button}}
                {{#help}}
                    {{>core/help_icon}}
                {{/help}}
            {{/begin}}
        </div>
        ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= $indent . '        <div>
';
                $value = $context->find('begin');
                $buffer .= $this->sectionE3fdd7a2b146d7ff432f87a81b3e3b36($context, $indent, $value);
                $value = $context->find('begin');
                if (empty($value)) {
                    
                    $value = $context->find('button');
                    $buffer .= $this->sectionC0a7a31fe8f3d761f2df8d39b4d04247($context, $indent, $value);
                    $value = $context->find('help');
                    $buffer .= $this->sectionE376858cf6101e5b36df1c2ecf1c24a3($context, $indent, $value);
                }
                $buffer .= $indent . '        </div>
';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function sectionFd2d2a372565d58d0d48f15436b0c7bf(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
        <div>
            {{#button}}
                {{>core/single_button}}
            {{/button}}
        </div>
        ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= $indent . '        <div>
';
                $value = $context->find('button');
                $buffer .= $this->section99d07c9571d5436bf3577ef619a3fbbd($context, $indent, $value);
                $buffer .= $indent . '        </div>
';
                $context->pop();
            }
        }
    
        return $buffer;
    }

}
