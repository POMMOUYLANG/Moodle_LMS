<?php

class __Mustache_6a9aab14f0784d5e11e21f42c6679c5f extends Mustache_Template
{
    private $lambdaHelper;

    public function renderInternal(Mustache_Context $context, $indent = '')
    {
        $this->lambdaHelper = new Mustache_LambdaHelper($this->mustache, $context);
        $buffer = '';

        $buffer .= $indent . '
';
        $buffer .= $indent . '<div class="row">
';
        $buffer .= $indent . '    <div id="page-second-header" class="col-12 pt-3 pb-3">
';
        $buffer .= $indent . '        <div class="d-flex flex-fill flex-wrap align-items-center';
        $value = $context->find('coursetitle');
        $buffer .= $this->section5dc175561733b4fdbbf2c65655c0aea6($context, $indent, $value);
        $buffer .= '">
';
        $value = $context->find('navbar');
        $buffer .= $this->section6d769f8accc0d92c9822898d394dc57f($context, $indent, $value);
        $value = $context->find('coursetitle');
        $buffer .= $this->section2db8a8e370bbb152582474f1efcd7965($context, $indent, $value);
        $buffer .= $indent . '        </div>
';
        $buffer .= $indent . '        <div class="d-flex align-items-center">
';
        $buffer .= $indent . '            <div class="me-auto d-flex flex-column">
';
        $value = $context->find('contextheader');
        $buffer .= $this->section1e19a6d094399d1c16bd40a76efe430b($context, $indent, $value);
        $value = $context->find('welcomemessage');
        $buffer .= $this->sectionF0984abecbe13ab2802dcb0e49e73930($context, $indent, $value);
        $buffer .= $indent . '            </div>
';
        $value = $context->find('headeritemsbottom');
        $buffer .= $this->sectionA28649f916c1846310cf1b5bf0ca1266($context, $indent, $value);
        $buffer .= $indent . '        </div>
';
        $buffer .= $indent . '    </div>
';
        $buffer .= $indent . '</div>
';

        return $buffer;
    }

    private function section5dc175561733b4fdbbf2c65655c0aea6(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = ' position-relative';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= ' position-relative';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section6e421b46a7b4898f9569005a737409f9(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
                <div class="header-action ms-2">{{{.}}}</div>
                ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= $indent . '                <div class="header-action ms-2">';
                $value = $this->resolveValue($context->last(), $context);
                $buffer .= ($value === null ? '' : $value);
                $buffer .= '</div>
';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section6d769f8accc0d92c9822898d394dc57f(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
            <div id="page-navbar" class="me-auto {{{headerclasses}}}">
                {{{navbar}}}
            </div>
            {{^headeritemsbottom}}
            <div class="header-actions-container ms-auto" data-region="header-actions-container">
                {{#headeractions}}
                <div class="header-action ms-2">{{{.}}}</div>
                {{/headeractions}}
            </div>
            {{/headeritemsbottom}}
            ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= $indent . '            <div id="page-navbar" class="me-auto ';
                $value = $this->resolveValue($context->find('headerclasses'), $context);
                $buffer .= ($value === null ? '' : $value);
                $buffer .= '">
';
                $buffer .= $indent . '                ';
                $value = $this->resolveValue($context->find('navbar'), $context);
                $buffer .= ($value === null ? '' : $value);
                $buffer .= '
';
                $buffer .= $indent . '            </div>
';
                $value = $context->find('headeritemsbottom');
                if (empty($value)) {
                    
                    $buffer .= $indent . '            <div class="header-actions-container ms-auto" data-region="header-actions-container">
';
                    $value = $context->find('headeractions');
                    $buffer .= $this->section6e421b46a7b4898f9569005a737409f9($context, $indent, $value);
                    $buffer .= $indent . '            </div>
';
                }
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section2db8a8e370bbb152582474f1efcd7965(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
            <div id="page-coursetitle" class="col-12">
                <div id="coursetitle" class="p-2 bd-highlight">
                    <h1>
                        <a href="{{coursetitleurl}}">
                            {{coursetitle}}
                        </a>
                    </h1>
                </div>
            </div>
            ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= $indent . '            <div id="page-coursetitle" class="col-12">
';
                $buffer .= $indent . '                <div id="coursetitle" class="p-2 bd-highlight">
';
                $buffer .= $indent . '                    <h1>
';
                $buffer .= $indent . '                        <a href="';
                $value = $this->resolveValue($context->find('coursetitleurl'), $context);
                $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
                $buffer .= '">
';
                $buffer .= $indent . '                            ';
                $value = $this->resolveValue($context->find('coursetitle'), $context);
                $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
                $buffer .= '
';
                $buffer .= $indent . '                        </a>
';
                $buffer .= $indent . '                    </h1>
';
                $buffer .= $indent . '                </div>
';
                $buffer .= $indent . '            </div>
';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section1e19a6d094399d1c16bd40a76efe430b(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
                <div>
                    {{{contextheader}}}
                </div>
                ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= $indent . '                <div>
';
                $buffer .= $indent . '                    ';
                $value = $this->resolveValue($context->find('contextheader'), $context);
                $buffer .= ($value === null ? '' : $value);
                $buffer .= '
';
                $buffer .= $indent . '                </div>
';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function sectionF0984abecbe13ab2802dcb0e49e73930(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
                <div>
                    {{> core/welcome }}
                </div>
                ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= $indent . '                <div>
';
                if ($partial = $this->mustache->loadPartial('core/welcome')) {
                    $buffer .= $partial->renderInternal($context, $indent . '                    ');
                }
                $buffer .= $indent . '                </div>
';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function sectionA28649f916c1846310cf1b5bf0ca1266(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
            <div class="header-actions-container ms-auto" data-region="header-actions-container">
                {{#headeractions}}
                <div class="header-action ms-2">{{{.}}}</div>
                {{/headeractions}}
            </div>
            ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= $indent . '            <div class="header-actions-container ms-auto" data-region="header-actions-container">
';
                $value = $context->find('headeractions');
                $buffer .= $this->section6e421b46a7b4898f9569005a737409f9($context, $indent, $value);
                $buffer .= $indent . '            </div>
';
                $context->pop();
            }
        }
    
        return $buffer;
    }

}
