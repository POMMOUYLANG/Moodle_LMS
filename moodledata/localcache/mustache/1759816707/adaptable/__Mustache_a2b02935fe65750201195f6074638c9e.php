<?php

class __Mustache_a2b02935fe65750201195f6074638c9e extends Mustache_Template
{
    private $lambdaHelper;

    public function renderInternal(Mustache_Context $context, $indent = '')
    {
        $this->lambdaHelper = new Mustache_LambdaHelper($this->mustache, $context);
        $buffer = '';

        $buffer .= $indent . '<h2>';
        $value = $context->find('str');
        $buffer .= $this->section70b52f5bf931f4439504571cdd1a296e($context, $indent, $value);
        $buffer .= '</h2>
';
        $value = $context->find('resetbutton');
        $buffer .= $this->section911c6551192b4e2633183e98de9de96d($context, $indent, $value);
        $buffer .= $indent . '<div class="card-grid row row-cols-1 row-cols-md-3 mx-0" id="themelist" data-region="card-deck" role="list" data-definedinconfig="';
        $value = $this->resolveValue($context->find('definedinconfig'), $context);
        $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
        $buffer .= '">
';
        $value = $context->find('themes');
        $buffer .= $this->section1a152b139495bc58a606321e993f27a5($context, $indent, $value);
        $buffer .= $indent . '</div>
';
        $buffer .= $indent . '
';
        $value = $context->find('js');
        $buffer .= $this->section2b52f261e9a607644d897667e2882e4c($context, $indent, $value);

        return $buffer;
    }

    private function section70b52f5bf931f4439504571cdd1a296e(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = 'themeselector, admin';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= 'themeselector, admin';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section911c6551192b4e2633183e98de9de96d(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
    <div class="mb-3">
        {{>core/single_button}}
    </div>
';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= $indent . '    <div class="mb-3">
';
                if ($partial = $this->mustache->loadPartial('core/single_button')) {
                    $buffer .= $partial->renderInternal($context, $indent . '        ');
                }
                $buffer .= $indent . '    </div>
';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section1a152b139495bc58a606321e993f27a5(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
        <div class="col d-flex px-1 mb-2">
            {{>core_admin/themeselector/theme_card}}
        </div>
    ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= $indent . '        <div class="col d-flex px-1 mb-2">
';
                if ($partial = $this->mustache->loadPartial('core_admin/themeselector/theme_card')) {
                    $buffer .= $partial->renderInternal($context, $indent . '            ');
                }
                $buffer .= $indent . '        </div>
';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section2b52f261e9a607644d897667e2882e4c(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
require([\'core_admin/themeselector/preview_modal\'], function(Modal) {
    Modal.init();
});
';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= $indent . 'require([\'core_admin/themeselector/preview_modal\'], function(Modal) {
';
                $buffer .= $indent . '    Modal.init();
';
                $buffer .= $indent . '});
';
                $context->pop();
            }
        }
    
        return $buffer;
    }

}
