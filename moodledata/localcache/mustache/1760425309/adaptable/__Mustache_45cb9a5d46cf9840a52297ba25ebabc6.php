<?php

class __Mustache_45cb9a5d46cf9840a52297ba25ebabc6 extends Mustache_Template
{
    private $lambdaHelper;

    public function renderInternal(Mustache_Context $context, $indent = '')
    {
        $this->lambdaHelper = new Mustache_LambdaHelper($this->mustache, $context);
        $buffer = '';

        $buffer .= $indent . '<div class="dropdown select-menu" id="';
        $value = $this->resolveValue($context->find('baseid'), $context);
        $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
        $buffer .= '">
';
        $buffer .= $indent . '    ';
        $value = $context->find('label');
        $buffer .= $this->section61f9b745029e44b14862db8d7a8cd3a7($context, $indent, $value);
        $buffer .= '
';
        $buffer .= $indent . '    <div
';
        $buffer .= $indent . '        class="btn dropdown-toggle';
        $value = $context->find('inlinelabel');
        $buffer .= $this->sectionB466402b104184019c3a927661167585($context, $indent, $value);
        $buffer .= '"
';
        $buffer .= $indent . '        role="combobox"
';
        $buffer .= $indent . '        data-bs-toggle="dropdown"
';
        $buffer .= $indent . '        ';
        $value = $context->find('label');
        $buffer .= $this->section21eed3cb7c61228a17106dfafdd2ae8a($context, $indent, $value);
        $buffer .= '
';
        $buffer .= $indent . '        aria-haspopup="listbox"
';
        $buffer .= $indent . '        aria-expanded="false"
';
        $buffer .= $indent . '        aria-controls="';
        $value = $this->resolveValue($context->find('baseid'), $context);
        $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
        $buffer .= '-listbox"
';
        $buffer .= $indent . '        data-input-element="';
        $value = $this->resolveValue($context->find('baseid'), $context);
        $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
        $buffer .= '-input"
';
        $buffer .= $indent . '        tabindex="0"
';
        $buffer .= $indent . '    >
';
        $value = $context->find('inlinelabel');
        $buffer .= $this->sectionC77bf3c6efb4993a31ffaba6bbd2719d($context, $indent, $value);
        $value = $context->find('inlinelabel');
        if (empty($value)) {
            
            $value = $context->find('headinglevel');
            $buffer .= $this->sectionC18cda0a6c66ff19a0b808c822af2b19($context, $indent, $value);
            $value = $context->find('headinglevel');
            if (empty($value)) {
                
                $buffer .= $indent . '                ';
                $value = $this->resolveValue($context->find('selectedoption'), $context);
                $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
                $buffer .= '
';
            }
        }
        $buffer .= $indent . '    </div>
';
        $buffer .= $indent . '    <ul class="dropdown-menu" role="listbox" id="';
        $value = $this->resolveValue($context->find('baseid'), $context);
        $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
        $buffer .= '-listbox" ';
        $value = $context->find('label');
        $buffer .= $this->section21eed3cb7c61228a17106dfafdd2ae8a($context, $indent, $value);
        $buffer .= '>
';
        $value = $context->find('options');
        $buffer .= $this->sectionEfcfc1421ba60f5b8accc81333a44596($context, $indent, $value);
        $buffer .= $indent . '    </ul>
';
        $buffer .= $indent . '    <input type="hidden" name="';
        $value = $this->resolveValue($context->find('name'), $context);
        $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
        $buffer .= '" value="';
        $value = $this->resolveValue($context->find('value'), $context);
        $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
        $buffer .= '" id="';
        $value = $this->resolveValue($context->find('baseid'), $context);
        $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
        $buffer .= '-input">
';
        $buffer .= $indent . '</div>
';
        $value = $context->find('js');
        $buffer .= $this->section0a15d5cbdbb6ce7298ad59318a53ec23($context, $indent, $value);

        return $buffer;
    }

    private function sectionAd20463c348991d5bbd2fb97358ea7c0(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = ' {{name}}="{{value}}"';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= ' ';
                $value = $this->resolveValue($context->find('name'), $context);
                $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
                $buffer .= '="';
                $value = $this->resolveValue($context->find('value'), $context);
                $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
                $buffer .= '"';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section61f9b745029e44b14862db8d7a8cd3a7(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '{{^inlinelabel}}
        <span id="{{baseid}}-label"{{#labelattributes}} {{name}}="{{value}}"{{/labelattributes}}>{{label}}</span>
    {{/inlinelabel}}';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $value = $context->find('inlinelabel');
                if (empty($value)) {
                    
                    $buffer .= '
';
                    $buffer .= $indent . '        <span id="';
                    $value = $this->resolveValue($context->find('baseid'), $context);
                    $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
                    $buffer .= '-label"';
                    $value = $context->find('labelattributes');
                    $buffer .= $this->sectionAd20463c348991d5bbd2fb97358ea7c0($context, $indent, $value);
                    $buffer .= '>';
                    $value = $this->resolveValue($context->find('label'), $context);
                    $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
                    $buffer .= '</span>
';
                    $buffer .= $indent . '    ';
                }
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function sectionB466402b104184019c3a927661167585(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = ' d-flex text-start align-items-center p-0';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= ' d-flex text-start align-items-center p-0';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section21eed3cb7c61228a17106dfafdd2ae8a(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = 'aria-labelledby="{{baseid}}-label"';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= 'aria-labelledby="';
                $value = $this->resolveValue($context->find('baseid'), $context);
                $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
                $buffer .= '-label"';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section3670875b9f43ac0719fab549d5034893(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
                role="heading"
                aria-level="{{headinglevel}}"
                ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= $indent . '                role="heading"
';
                $buffer .= $indent . '                aria-level="';
                $value = $this->resolveValue($context->find('headinglevel'), $context);
                $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
                $buffer .= '"
';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section01706859767b766150ad46cff0f16d99(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
                    <span class="d-block m-0 small" id="{{baseid}}-label"{{#labelattributes}} {{name}}="{{value}}"{{/labelattributes}}>{{label}}</span>
                ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= $indent . '                    <span class="d-block m-0 small" id="';
                $value = $this->resolveValue($context->find('baseid'), $context);
                $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
                $buffer .= '-label"';
                $value = $context->find('labelattributes');
                $buffer .= $this->sectionAd20463c348991d5bbd2fb97358ea7c0($context, $indent, $value);
                $buffer .= '>';
                $value = $this->resolveValue($context->find('label'), $context);
                $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
                $buffer .= '</span>
';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function sectionC77bf3c6efb4993a31ffaba6bbd2719d(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
            <div
                class="pe-3 text-truncate"
                {{#headinglevel}}
                role="heading"
                aria-level="{{headinglevel}}"
                {{/headinglevel}}
            >
                {{#label}}
                    <span class="d-block m-0 small" id="{{baseid}}-label"{{#labelattributes}} {{name}}="{{value}}"{{/labelattributes}}>{{label}}</span>
                {{/label}}
                <span class="fw-bold" data-selected-option>
                    {{selectedoption}}
                </span>
            </div>
        ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= $indent . '            <div
';
                $buffer .= $indent . '                class="pe-3 text-truncate"
';
                $value = $context->find('headinglevel');
                $buffer .= $this->section3670875b9f43ac0719fab549d5034893($context, $indent, $value);
                $buffer .= $indent . '            >
';
                $value = $context->find('label');
                $buffer .= $this->section01706859767b766150ad46cff0f16d99($context, $indent, $value);
                $buffer .= $indent . '                <span class="fw-bold" data-selected-option>
';
                $buffer .= $indent . '                    ';
                $value = $this->resolveValue($context->find('selectedoption'), $context);
                $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
                $buffer .= '
';
                $buffer .= $indent . '                </span>
';
                $buffer .= $indent . '            </div>
';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function sectionC18cda0a6c66ff19a0b808c822af2b19(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
                <span
                    role="heading"
                    aria-level="{{headinglevel}}"
                >
                    {{selectedoption}}
                </span>
            ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= $indent . '                <span
';
                $buffer .= $indent . '                    role="heading"
';
                $buffer .= $indent . '                    aria-level="';
                $value = $this->resolveValue($context->find('headinglevel'), $context);
                $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
                $buffer .= '"
';
                $buffer .= $indent . '                >
';
                $buffer .= $indent . '                    ';
                $value = $this->resolveValue($context->find('selectedoption'), $context);
                $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
                $buffer .= '
';
                $buffer .= $indent . '                </span>
';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function sectionD0f33c3203e3e02cc7546d41e697de7a(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
                                <li role="separator" class="dropdown-divider"></li>
                            ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= $indent . '                                <li role="separator" class="dropdown-divider"></li>
';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function sectionCe04cacc15f032e9e9f826b761c9b814(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = 'aria-selected="true"';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= 'aria-selected="true"';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section81b331b5e9f34538151291a82ee28d46(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
                            {{#isdivider}}
                                <li role="separator" class="dropdown-divider"></li>
                            {{/isdivider}}
                            {{^isdivider}}
                                <li class="dropdown-item" role="option" id="{{id}}" data-value="{{value}}" {{#selected}}aria-selected="true"{{/selected}}>
                                    {{name}}
                                </li>
                            {{/isdivider}}
                        ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $value = $context->find('isdivider');
                $buffer .= $this->sectionD0f33c3203e3e02cc7546d41e697de7a($context, $indent, $value);
                $value = $context->find('isdivider');
                if (empty($value)) {
                    
                    $buffer .= $indent . '                                <li class="dropdown-item" role="option" id="';
                    $value = $this->resolveValue($context->find('id'), $context);
                    $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
                    $buffer .= '" data-value="';
                    $value = $this->resolveValue($context->find('value'), $context);
                    $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
                    $buffer .= '" ';
                    $value = $context->find('selected');
                    $buffer .= $this->sectionCe04cacc15f032e9e9f826b761c9b814($context, $indent, $value);
                    $buffer .= '>
';
                    $buffer .= $indent . '                                    ';
                    $value = $this->resolveValue($context->find('name'), $context);
                    $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
                    $buffer .= '
';
                    $buffer .= $indent . '                                </li>
';
                }
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function sectionC1d3bab6c0d741ddb52ffc43eab3bafb(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
                <li role="none">
                    <ul role="group" aria-labelledby="{{id}}">
                        <li role="presentation" id="{{id}}">{{name}}</li>
                        {{#options}}
                            {{#isdivider}}
                                <li role="separator" class="dropdown-divider"></li>
                            {{/isdivider}}
                            {{^isdivider}}
                                <li class="dropdown-item" role="option" id="{{id}}" data-value="{{value}}" {{#selected}}aria-selected="true"{{/selected}}>
                                    {{name}}
                                </li>
                            {{/isdivider}}
                        {{/options}}
                    </ul>
                </li>
            ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= $indent . '                <li role="none">
';
                $buffer .= $indent . '                    <ul role="group" aria-labelledby="';
                $value = $this->resolveValue($context->find('id'), $context);
                $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
                $buffer .= '">
';
                $buffer .= $indent . '                        <li role="presentation" id="';
                $value = $this->resolveValue($context->find('id'), $context);
                $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
                $buffer .= '">';
                $value = $this->resolveValue($context->find('name'), $context);
                $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
                $buffer .= '</li>
';
                $value = $context->find('options');
                $buffer .= $this->section81b331b5e9f34538151291a82ee28d46($context, $indent, $value);
                $buffer .= $indent . '                    </ul>
';
                $buffer .= $indent . '                </li>
';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section46a34dc96a8b15a85d43c45e177ebca8(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
                    <li role="separator" class="dropdown-divider"></li>
                ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= $indent . '                    <li role="separator" class="dropdown-divider"></li>
';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function sectionEfcfc1421ba60f5b8accc81333a44596(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
            {{#isgroup}}
                <li role="none">
                    <ul role="group" aria-labelledby="{{id}}">
                        <li role="presentation" id="{{id}}">{{name}}</li>
                        {{#options}}
                            {{#isdivider}}
                                <li role="separator" class="dropdown-divider"></li>
                            {{/isdivider}}
                            {{^isdivider}}
                                <li class="dropdown-item" role="option" id="{{id}}" data-value="{{value}}" {{#selected}}aria-selected="true"{{/selected}}>
                                    {{name}}
                                </li>
                            {{/isdivider}}
                        {{/options}}
                    </ul>
                </li>
            {{/isgroup}}
            {{^isgroup}}
                {{#isdivider}}
                    <li role="separator" class="dropdown-divider"></li>
                {{/isdivider}}
                {{^isdivider}}
                    <li class="dropdown-item" role="option" id="{{id}}" data-value="{{value}}" {{#selected}}aria-selected="true"{{/selected}}>
                        {{name}}
                    </li>
                {{/isdivider}}
            {{/isgroup}}
        ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $value = $context->find('isgroup');
                $buffer .= $this->sectionC1d3bab6c0d741ddb52ffc43eab3bafb($context, $indent, $value);
                $value = $context->find('isgroup');
                if (empty($value)) {
                    
                    $value = $context->find('isdivider');
                    $buffer .= $this->section46a34dc96a8b15a85d43c45e177ebca8($context, $indent, $value);
                    $value = $context->find('isdivider');
                    if (empty($value)) {
                        
                        $buffer .= $indent . '                    <li class="dropdown-item" role="option" id="';
                        $value = $this->resolveValue($context->find('id'), $context);
                        $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
                        $buffer .= '" data-value="';
                        $value = $this->resolveValue($context->find('value'), $context);
                        $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
                        $buffer .= '" ';
                        $value = $context->find('selected');
                        $buffer .= $this->sectionCe04cacc15f032e9e9f826b761c9b814($context, $indent, $value);
                        $buffer .= '>
';
                        $buffer .= $indent . '                        ';
                        $value = $this->resolveValue($context->find('name'), $context);
                        $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
                        $buffer .= '
';
                        $buffer .= $indent . '                    </li>
';
                    }
                }
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section0a15d5cbdbb6ce7298ad59318a53ec23(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
    var label = document.getElementById(\'{{baseid}}-label\');
    if (label) {
        label.addEventListener(\'click\', function() {
            this.parentElement.querySelector(\'.dropdown-toggle\').focus();
        });
    }
';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= $indent . '    var label = document.getElementById(\'';
                $value = $this->resolveValue($context->find('baseid'), $context);
                $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
                $buffer .= '-label\');
';
                $buffer .= $indent . '    if (label) {
';
                $buffer .= $indent . '        label.addEventListener(\'click\', function() {
';
                $buffer .= $indent . '            this.parentElement.querySelector(\'.dropdown-toggle\').focus();
';
                $buffer .= $indent . '        });
';
                $buffer .= $indent . '    }
';
                $context->pop();
            }
        }
    
        return $buffer;
    }

}
