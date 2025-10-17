<?php

class __Mustache_7afd75261a55560958bb7b94ddd5a447 extends Mustache_Template
{
    private $lambdaHelper;

    public function renderInternal(Mustache_Context $context, $indent = '')
    {
        $this->lambdaHelper = new Mustache_LambdaHelper($this->mustache, $context);
        $buffer = '';

        $value = $context->find('hassidepost');
        $buffer .= $this->section63b6e1628c79ce5b36bdcdc64b8cc1d3($context, $indent, $value);

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

    private function section1cb47d3ed9b97c6d6d496d2358bec253(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = ' show';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= ' show';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function sectionB41f9fd909a0b705eeb8f603ddd82d78(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = ' d-none';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= ' d-none';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function sectionC14df02445cdd505a0208e8a56a5f32e(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = 'blocks';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= 'blocks';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section1bd0cc4642e36d67e46c9dd550f1fa06(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '1';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= '1';
                $context->pop();
            }
        }
    
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

    private function section31618380a8d2d407a8b2acf35dd449a4(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = 'closeblockdrawer, core';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= 'closeblockdrawer, core';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section63b6e1628c79ce5b36bdcdc64b8cc1d3(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
    {{< theme_boost/drawer }}
        {{$id}}theme_adaptable-drawers-sidepost{{/id}}
        {{$drawerclasses}}drawer drawer-{{#left}}left{{/left}}{{^left}}right{{/left}}{{#sidepostopen}} show{{/sidepostopen}}{{#stickynavbar}} d-none{{/stickynavbar}}{{/drawerclasses}}
        {{$drawercontent}}
            <section class="d-print-none" aria-label="{{#str}}blocks{{/str}}">
                {{{ sidepost }}}
            </section>
        {{/drawercontent}}
        {{$drawerpreferencename}}drawer-open-block{{/drawerpreferencename}}
        {{$forceopen}}{{#forceblockdraweropen}}1{{/forceblockdraweropen}}{{/forceopen}}
        {{$drawerstate}}show-drawer-{{#left}}left{{/left}}{{^left}}right{{/left}}{{/drawerstate}}
        {{$tooltipplacement}}{{#left}}right{{/left}}{{^left}}left{{/left}}{{/tooltipplacement}}
        {{$drawercloseonresize}}1{{/drawercloseonresize}}
        {{$closebuttontext}}{{#str}}closeblockdrawer, core{{/str}}{{/closebuttontext}}
    {{/ theme_boost/drawer}}
';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= $indent . '    ';
                if ($parent = $this->mustache->loadPartial('theme_boost/drawer')) {
                    $context->pushBlockContext(array(
                        'id' => array($this, 'block75f615dc888b94b01b3bd84323fe8bc0'),
                        'drawerclasses' => array($this, 'block82a4e06948656a9bb8586494fb1999d2'),
                        'drawercontent' => array($this, 'blockF8f0d5daac86347380dc32b3e6180b16'),
                        'drawerpreferencename' => array($this, 'block59146569a56c3d2642fa2e8224817be0'),
                        'forceopen' => array($this, 'block13847ba3219919ecdb2378620735177c'),
                        'drawerstate' => array($this, 'block78ab9a636f099baf3206f5882c0c32ee'),
                        'tooltipplacement' => array($this, 'blockA6f5eee7c8f3872371e0c75aa1765f90'),
                        'drawercloseonresize' => array($this, 'blockE052079a625ca42b568ba24af19cc7eb'),
                        'closebuttontext' => array($this, 'blockC879444321d250421c3438099ae68173'),
                    ));
                    $buffer .= $parent->renderInternal($context, $indent);
                    $context->popBlockContext();
                }
                $context->pop();
            }
        }
    
        return $buffer;
    }

    public function block75f615dc888b94b01b3bd84323fe8bc0($context)
    {
        $indent = $buffer = '';
        $buffer .= 'theme_adaptable-drawers-sidepost';
    
        return $buffer;
    }

    public function block82a4e06948656a9bb8586494fb1999d2($context)
    {
        $indent = $buffer = '';
        $buffer .= 'drawer drawer-';
        $value = $context->find('left');
        $buffer .= $this->section0aa6fe3b3c41579e49bb7bcc3c6a53a1($context, $indent, $value);
        $value = $context->find('left');
        if (empty($value)) {
            
            $buffer .= 'right';
        }
        $value = $context->find('sidepostopen');
        $buffer .= $this->section1cb47d3ed9b97c6d6d496d2358bec253($context, $indent, $value);
        $value = $context->find('stickynavbar');
        $buffer .= $this->sectionB41f9fd909a0b705eeb8f603ddd82d78($context, $indent, $value);
    
        return $buffer;
    }

    public function blockF8f0d5daac86347380dc32b3e6180b16($context)
    {
        $indent = $buffer = '';
        $buffer .= '            <section class="d-print-none" aria-label="';
        $value = $context->find('str');
        $buffer .= $this->sectionC14df02445cdd505a0208e8a56a5f32e($context, $indent, $value);
        $buffer .= '">
';
        $buffer .= $indent . '                ';
        $value = $this->resolveValue($context->find('sidepost'), $context);
        $buffer .= ($value === null ? '' : $value);
        $buffer .= '
';
        $buffer .= $indent . '            </section>
';
    
        return $buffer;
    }

    public function block59146569a56c3d2642fa2e8224817be0($context)
    {
        $indent = $buffer = '';
        $buffer .= $indent . 'drawer-open-block';
    
        return $buffer;
    }

    public function block13847ba3219919ecdb2378620735177c($context)
    {
        $indent = $buffer = '';
        $value = $context->find('forceblockdraweropen');
        $buffer .= $this->section1bd0cc4642e36d67e46c9dd550f1fa06($context, $indent, $value);
    
        return $buffer;
    }

    public function block78ab9a636f099baf3206f5882c0c32ee($context)
    {
        $indent = $buffer = '';
        $buffer .= 'show-drawer-';
        $value = $context->find('left');
        $buffer .= $this->section0aa6fe3b3c41579e49bb7bcc3c6a53a1($context, $indent, $value);
        $value = $context->find('left');
        if (empty($value)) {
            
            $buffer .= 'right';
        }
    
        return $buffer;
    }

    public function blockA6f5eee7c8f3872371e0c75aa1765f90($context)
    {
        $indent = $buffer = '';
        $value = $context->find('left');
        $buffer .= $this->section6f33152a41341e2c397de871a1796b75($context, $indent, $value);
        $value = $context->find('left');
        if (empty($value)) {
            
            $buffer .= 'left';
        }
    
        return $buffer;
    }

    public function blockE052079a625ca42b568ba24af19cc7eb($context)
    {
        $indent = $buffer = '';
        $buffer .= '1';
    
        return $buffer;
    }

    public function blockC879444321d250421c3438099ae68173($context)
    {
        $indent = $buffer = '';
        $value = $context->find('str');
        $buffer .= $this->section31618380a8d2d407a8b2acf35dd449a4($context, $indent, $value);
    
        return $buffer;
    }
}
