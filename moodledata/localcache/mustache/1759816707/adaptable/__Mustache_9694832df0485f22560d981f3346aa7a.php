<?php

class __Mustache_9694832df0485f22560d981f3346aa7a extends Mustache_Template
{
    private $lambdaHelper;

    public function renderInternal(Mustache_Context $context, $indent = '')
    {
        $this->lambdaHelper = new Mustache_LambdaHelper($this->mustache, $context);
        $buffer = '';

        $buffer .= $indent . '<div class="alert ';
        $blockFunction = $context->findInBlock('alertclass');
        if (is_callable($blockFunction)) {
            $buffer .= call_user_func($blockFunction, $context);
        } else {
            $buffer .= 'alert-secondary';
        }
        $buffer .= ' alert-block fade in ';
        $value = $this->resolveValue($context->find('extraclasses'), $context);
        $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
        $buffer .= ' ';
        $value = $context->find('closebutton');
        $buffer .= $this->section53bca60b6e3724bf520a7dc0d7e91a16($context, $indent, $value);
        $buffer .= '" ';
        $value = $context->find('announce');
        $buffer .= $this->section9475860da7a90ce0c1cbee01b0f651f9($context, $indent, $value);
        $buffer .= '>
';
        $value = $context->find('title');
        $buffer .= $this->section2520b7131a5a7d24cd2758ea2597c0e3($context, $indent, $value);
        $buffer .= $indent . '    ';
        $value = $this->resolveValue($context->find('message'), $context);
        $buffer .= ($value === null ? '' : $value);
        $buffer .= '
';
        $buffer .= $indent . '    ';
        $value = $context->find('closebutton');
        $buffer .= $this->section38caba7354221a4cbbb4e2e5d1730033($context, $indent, $value);
        $buffer .= '
';
        $buffer .= $indent . '</div>
';

        return $buffer;
    }

    private function section53bca60b6e3724bf520a7dc0d7e91a16(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = 'alert-dismissible';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= 'alert-dismissible';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section9475860da7a90ce0c1cbee01b0f651f9(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = ' role="alert" data-aria-autofocus="true"';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= ' role="alert" data-aria-autofocus="true"';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function sectionB4b6c783486c94dfd5d81ddce762c71b(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '{{icon}}, {{component}}';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $value = $this->resolveValue($context->find('icon'), $context);
                $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
                $buffer .= ', ';
                $value = $this->resolveValue($context->find('component'), $context);
                $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section75e4774bdf4dec559034965999a7a912(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
                <span>
                    {{#pix}}{{icon}}, {{component}}{{/pix}}
                </span>
            ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= $indent . '                <span>
';
                $buffer .= $indent . '                    ';
                $value = $context->find('pix');
                $buffer .= $this->sectionB4b6c783486c94dfd5d81ddce762c71b($context, $indent, $value);
                $buffer .= '
';
                $buffer .= $indent . '                </span>
';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section2520b7131a5a7d24cd2758ea2597c0e3(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
        <div class="mb-2 mt-1 d-flex alert-heading">
            {{#titleicon}}
                <span>
                    {{#pix}}{{icon}}, {{component}}{{/pix}}
                </span>
            {{/titleicon}}
            <h3 class="h6 mb-0">
                <span class="align-middle">{{.}}</span>
            </h3>
        </div>
    ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= $indent . '        <div class="mb-2 mt-1 d-flex alert-heading">
';
                $value = $context->find('titleicon');
                $buffer .= $this->section75e4774bdf4dec559034965999a7a912($context, $indent, $value);
                $buffer .= $indent . '            <h3 class="h6 mb-0">
';
                $buffer .= $indent . '                <span class="align-middle">';
                $value = $this->resolveValue($context->last(), $context);
                $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
                $buffer .= '</span>
';
                $buffer .= $indent . '            </h3>
';
                $buffer .= $indent . '        </div>
';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section62fce6eb1e22a38c9f1bd33645ed3cc6(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = 'dismissnotification, core';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= 'dismissnotification, core';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section38caba7354221a4cbbb4e2e5d1730033(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '{{!
    }}<button type="button" class="btn-close" data-bs-dismiss="alert">
        <span class="visually-hidden">{{#str}}dismissnotification, core{{/str}}</span>
    </button>{{!
    }}';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= '<button type="button" class="btn-close" data-bs-dismiss="alert">
';
                $buffer .= $indent . '        <span class="visually-hidden">';
                $value = $context->find('str');
                $buffer .= $this->section62fce6eb1e22a38c9f1bd33645ed3cc6($context, $indent, $value);
                $buffer .= '</span>
';
                $buffer .= $indent . '    </button>';
                $context->pop();
            }
        }
    
        return $buffer;
    }

}
