<?php

class __Mustache_2c78f4ee947f9b1af2d715953daba272 extends Mustache_Template
{
    private $lambdaHelper;

    public function renderInternal(Mustache_Context $context, $indent = '')
    {
        $this->lambdaHelper = new Mustache_LambdaHelper($this->mustache, $context);
        $buffer = '';

        if ($parent = $this->mustache->loadPartial('core_form/element-template')) {
            $context->pushBlockContext(array(
                'element' => array($this, 'blockFd34293febdca78c03ae720e5fe9938e'),
            ));
            $buffer .= $parent->renderInternal($context, $indent);
            $context->popBlockContext();
        }
        $value = $context->find('js');
        $buffer .= $this->section8454ba76b22dc4b75fc5582cf255f362($context, $indent, $value);

        return $buffer;
    }

    private function sectionBd6d241829fcbe59e01506b6f6c8d128(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = 'readonly';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= 'readonly';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function sectionCc48dcea9d2838da4d0362b228628420(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = 'is-invalid';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= 'is-invalid';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section8afa27ebe1f9655647f44ddb1e20a0cf(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
                                autofocus aria-describedby="{{ element.iderror }}"
                            ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= $indent . '                                autofocus aria-describedby="';
                $value = $this->resolveValue($context->findDot('element.iderror'), $context);
                $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
                $buffer .= '"
';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section1d933a0e0ac44dfbc3b021a15ca12ba9(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
                                aria-required="true"
                            ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= $indent . '                                aria-required="true"
';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function sectionB1966ed63f39357352a1e773a9fe2c4a(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = ' passwordunmaskedithint, form ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= ' passwordunmaskedithint, form ';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function sectionA7d3cdaa80a4fb55cbbbdcf6e8230551(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = ' t/passwordunmask-edit, core, {{# str }} passwordunmaskedithint, form {{/ str }}';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= ' t/passwordunmask-edit, core, ';
                $value = $context->find('str');
                $buffer .= $this->sectionB1966ed63f39357352a1e773a9fe2c4a($context, $indent, $value);
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section0456df008114280c7908271e554ab4e3(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = ' passwordunmaskrevealhint, form ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= ' passwordunmaskrevealhint, form ';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function sectionD238d708b51d531766660a637c3be472(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = ' t/passwordunmask-reveal, core, {{# str }} passwordunmaskrevealhint, form {{/ str }}';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= ' t/passwordunmask-reveal, core, ';
                $value = $context->find('str');
                $buffer .= $this->section0456df008114280c7908271e554ab4e3($context, $indent, $value);
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function sectionF86965103a62cc37f20777605d623629(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = ' passwordunmaskinstructions, form ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= ' passwordunmaskinstructions, form ';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section8454ba76b22dc4b75fc5582cf255f362(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
require([\'core_form/passwordunmask\'], function(PasswordUnmask) {
    new PasswordUnmask("{{ element.id }}");
});
';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= $indent . 'require([\'core_form/passwordunmask\'], function(PasswordUnmask) {
';
                $buffer .= $indent . '    new PasswordUnmask("';
                $value = $this->resolveValue($context->findDot('element.id'), $context);
                $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
                $buffer .= '");
';
                $buffer .= $indent . '});
';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    public function blockFd34293febdca78c03ae720e5fe9938e($context)
    {
        $indent = $buffer = '';
        $buffer .= $indent . '        <div data-passwordunmask="wrapper" data-passwordunmaskid="';
        $value = $this->resolveValue($context->findDot('element.id'), $context);
        $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
        $buffer .= '">
';
        $buffer .= $indent . '            <div class="d-flex align-items-center">
';
        $buffer .= $indent . '                <span data-passwordunmask="editor">
';
        $buffer .= $indent . '                    <input  type="password"
';
        $buffer .= $indent . '                            ';
        $value = $context->findDot('element.frozen');
        $buffer .= $this->sectionBd6d241829fcbe59e01506b6f6c8d128($context, $indent, $value);
        $buffer .= '
';
        $buffer .= $indent . '                            ';
        $value = $context->findDot('element.hardfrozen');
        if (empty($value)) {
            
            $buffer .= ' name="';
            $value = $this->resolveValue($context->findDot('element.name'), $context);
            $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
            $buffer .= '"';
        }
        $buffer .= '
';
        $buffer .= $indent . '                            id="';
        $value = $this->resolveValue($context->findDot('element.id'), $context);
        $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
        $buffer .= '"
';
        $buffer .= $indent . '                            value="';
        $value = $this->resolveValue($context->findDot('element.value'), $context);
        $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
        $buffer .= '"
';
        $buffer .= $indent . '                            class="form-control d-none ';
        $value = $context->find('error');
        $buffer .= $this->sectionCc48dcea9d2838da4d0362b228628420($context, $indent, $value);
        $buffer .= '"
';
        $buffer .= $indent . '                            data-size="';
        $value = $this->resolveValue($context->findDot('element.size'), $context);
        $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
        $buffer .= '"
';
        $value = $context->find('error');
        $buffer .= $this->section8afa27ebe1f9655647f44ddb1e20a0cf($context, $indent, $value);
        $value = $context->find('required');
        $buffer .= $this->section1d933a0e0ac44dfbc3b021a15ca12ba9($context, $indent, $value);
        $buffer .= $indent . '                            ';
        $value = $this->resolveValue($context->findDot('element.attributes'), $context);
        $buffer .= ($value === null ? '' : $value);
        $buffer .= '
';
        $buffer .= $indent . '                            >
';
        $buffer .= $indent . '                </span>
';
        $value = $context->findDot('element.frozen');
        if (empty($value)) {
            
            $buffer .= $indent . '                <a href="#" class="form-control" data-passwordunmask="edit" title="';
            $value = $this->resolveValue($context->find('edithint'), $context);
            $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
            $buffer .= '">
';
        }
        $buffer .= $indent . '                    <span data-passwordunmask="displayvalue">';
        if ($partial = $this->mustache->loadPartial('core_form/element-passwordunmask-fill')) {
            $buffer .= $partial->renderInternal($context);
        }
        $buffer .= '</span>
';
        $value = $context->findDot('element.frozen');
        if (empty($value)) {
            
            $buffer .= $indent . '                    ';
            $value = $context->find('pix');
            $buffer .= $this->sectionA7d3cdaa80a4fb55cbbbdcf6e8230551($context, $indent, $value);
            $buffer .= '
';
            $buffer .= $indent . '                </a>
';
        }
        $buffer .= $indent . '                <a href="#" class="btn btn-link icon-no-margin p-1 ms-1" data-passwordunmask="unmask" title="';
        $value = $this->resolveValue($context->find('unmaskhint'), $context);
        $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
        $buffer .= '">
';
        $buffer .= $indent . '                    ';
        $value = $context->find('pix');
        $buffer .= $this->sectionD238d708b51d531766660a637c3be472($context, $indent, $value);
        $buffer .= '
';
        $buffer .= $indent . '                </a>
';
        $buffer .= $indent . '            </div>
';
        $buffer .= $indent . '            <span data-passwordunmask="instructions" class="form-text text-muted" style="display: none;">
';
        $value = $context->findDot('element.frozen');
        if (empty($value)) {
            
            $buffer .= $indent . '                ';
            $value = $context->find('str');
            $buffer .= $this->sectionF86965103a62cc37f20777605d623629($context, $indent, $value);
            $buffer .= '
';
        }
        $buffer .= $indent . '            </span>
';
        $buffer .= $indent . '        </div>
';
    
        return $buffer;
    }
}
