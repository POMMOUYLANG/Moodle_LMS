<?php

class __Mustache_78a1741649a28669bfcbf60181d2ae2f extends Mustache_Template
{
    private $lambdaHelper;

    public function renderInternal(Mustache_Context $context, $indent = '')
    {
        $this->lambdaHelper = new Mustache_LambdaHelper($this->mustache, $context);
        $buffer = '';

        $buffer .= $indent . '<div class="reportbuilder-report ';
        $value = $this->resolveValue($context->find('classes'), $context);
        $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
        $buffer .= '"
';
        $buffer .= $indent . '        id="reportbuilder-report-';
        $value = $this->resolveValue($context->find('uniqid'), $context);
        $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
        $buffer .= '"
';
        $buffer .= $indent . '        data-region="core_reportbuilder/report"
';
        $buffer .= $indent . '        data-report-id="';
        $value = $this->resolveValue($context->find('id'), $context);
        $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
        $buffer .= '"
';
        $buffer .= $indent . '        data-report-type="';
        $value = $this->resolveValue($context->find('type'), $context);
        $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
        $buffer .= '"
';
        $buffer .= $indent . '        data-report-parameters="';
        $value = $this->resolveValue($context->find('parameters'), $context);
        $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
        $buffer .= '"
';
        $buffer .= $indent . '        ';
        $value = $context->find('attributes');
        $buffer .= $this->section6805fd502f1e55bd3a63b02c625bf221($context, $indent, $value);
        $buffer .= '>
';
        $buffer .= $indent . '            <div class="reportbuilder-wrapper">
';
        $buffer .= $indent . '                <div class="dropdown d-flex justify-content-end mb-3">
';
        $value = $context->find('button');
        $buffer .= $this->section6a688f2c15610e453b900bf3257b66dc($context, $indent, $value);
        $value = $context->find('filterspresent');
        $buffer .= $this->section926207327fe15031436ab3c83a1e977a($context, $indent, $value);
        $buffer .= $indent . '                </div>
';
        $buffer .= $indent . '                ';
        $value = $context->find('infocontainer');
        $buffer .= $this->section03154ac2ef404738a1138611b1d128a8($context, $indent, $value);
        $buffer .= '
';
        $buffer .= $indent . '                <div class="mt-2">';
        $value = $this->resolveValue($context->find('table'), $context);
        $buffer .= ($value === null ? '' : $value);
        $buffer .= '</div>
';
        $buffer .= $indent . '            </div>
';
        $buffer .= $indent . '</div>
';
        $buffer .= $indent . '
';
        $value = $context->find('js');
        $buffer .= $this->section2af2ff9e490aca675e8e8dd343a5a2de($context, $indent, $value);

        return $buffer;
    }

    private function section6805fd502f1e55bd3a63b02c625bf221(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '{{name}}="{{value}}" ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $value = $this->resolveValue($context->find('name'), $context);
                $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
                $buffer .= '="';
                $value = $this->resolveValue($context->find('value'), $context);
                $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
                $buffer .= '" ';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section0c0ef0d2a3fff419a40cec8e05910f45(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '{{key}}, {{component}}, {{{title}}}';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $value = $this->resolveValue($context->find('key'), $context);
                $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
                $buffer .= ', ';
                $value = $this->resolveValue($context->find('component'), $context);
                $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
                $buffer .= ', ';
                $value = $this->resolveValue($context->find('title'), $context);
                $buffer .= ($value === null ? '' : $value);
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function sectionCac6a6ab08930840c02d0c169eaeedba(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '{{#pix}}{{key}}, {{component}}, {{{title}}}{{/pix}}';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $value = $context->find('pix');
                $buffer .= $this->section0c0ef0d2a3fff419a40cec8e05910f45($context, $indent, $value);
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section6a688f2c15610e453b900bf3257b66dc(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
                        <{{{tag}}} {{#attributes}}{{name}}="{{value}}" {{/attributes}}>
                            {{#icon}}{{#pix}}{{key}}, {{component}}, {{{title}}}{{/pix}}{{/icon}}
                            {{{title}}}
                        </{{{tag}}}>
                    ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= $indent . '                        <';
                $value = $this->resolveValue($context->find('tag'), $context);
                $buffer .= ($value === null ? '' : $value);
                $buffer .= ' ';
                $value = $context->find('attributes');
                $buffer .= $this->section6805fd502f1e55bd3a63b02c625bf221($context, $indent, $value);
                $buffer .= '>
';
                $buffer .= $indent . '                            ';
                $value = $context->find('icon');
                $buffer .= $this->sectionCac6a6ab08930840c02d0c169eaeedba($context, $indent, $value);
                $buffer .= '
';
                $buffer .= $indent . '                            ';
                $value = $this->resolveValue($context->find('title'), $context);
                $buffer .= ($value === null ? '' : $value);
                $buffer .= '
';
                $buffer .= $indent . '                        </';
                $value = $this->resolveValue($context->find('tag'), $context);
                $buffer .= ($value === null ? '' : $value);
                $buffer .= '>
';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section926207327fe15031436ab3c83a1e977a(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
                        {{>core_reportbuilder/local/filters/area}}
                    ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                if ($partial = $this->mustache->loadPartial('core_reportbuilder/local/filters/area')) {
                    $buffer .= $partial->renderInternal($context, $indent . '                        ');
                }
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section03154ac2ef404738a1138611b1d128a8(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '<div class="mt-2">{{{.}}}</div>';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= '<div class="mt-2">';
                $value = $this->resolveValue($context->last(), $context);
                $buffer .= ($value === null ? '' : $value);
                $buffer .= '</div>';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section2af2ff9e490aca675e8e8dd343a5a2de(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
    require([\'core_reportbuilder/report\'], function(report) {
        report.init();
    });
';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= $indent . '    require([\'core_reportbuilder/report\'], function(report) {
';
                $buffer .= $indent . '        report.init();
';
                $buffer .= $indent . '    });
';
                $context->pop();
            }
        }
    
        return $buffer;
    }

}
