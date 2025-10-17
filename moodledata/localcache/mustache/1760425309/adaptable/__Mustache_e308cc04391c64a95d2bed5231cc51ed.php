<?php

class __Mustache_e308cc04391c64a95d2bed5231cc51ed extends Mustache_Template
{
    private $lambdaHelper;

    public function renderInternal(Mustache_Context $context, $indent = '')
    {
        $this->lambdaHelper = new Mustache_LambdaHelper($this->mustache, $context);
        $buffer = '';

        $buffer .= $indent . '<div data-region="loading-placeholder-content" aria-hidden="true" id="course-index-placeholder">
';
        $buffer .= $indent . '    <ul class="placeholders list-unstyled px-5">
';
        $buffer .= $indent . '        <li>
';
        $buffer .= $indent . '            <div class="col-md-6 p-0 d-flex align-items-center">
';
        $buffer .= $indent . '                <div class="bg-pulse-grey rounded-circle me-2"></div>
';
        $buffer .= $indent . '                <div class="bg-pulse-grey w-100"></div>
';
        $buffer .= $indent . '            </div>
';
        $buffer .= $indent . '        </li>
';
        $buffer .= $indent . '        <li>
';
        $buffer .= $indent . '            <div class="col-md-6 p-0 d-flex align-items-center">
';
        $buffer .= $indent . '                <div class="bg-pulse-grey rounded-circle me-2"></div>
';
        $buffer .= $indent . '                <div class="bg-pulse-grey w-100"></div>
';
        $buffer .= $indent . '            </div>
';
        $buffer .= $indent . '        </li>
';
        $buffer .= $indent . '        <li>
';
        $buffer .= $indent . '            <div class="col-md-6 p-0 d-flex align-items-center">
';
        $buffer .= $indent . '                <div class="bg-pulse-grey rounded-circle me-2"></div>
';
        $buffer .= $indent . '                <div class="bg-pulse-grey w-100"></div>
';
        $buffer .= $indent . '            </div>
';
        $buffer .= $indent . '        </li>
';
        $buffer .= $indent . '        <li>
';
        $buffer .= $indent . '            <div class="col-md-6 p-0 d-flex align-items-center">
';
        $buffer .= $indent . '                <div class="bg-pulse-grey rounded-circle me-2"></div>
';
        $buffer .= $indent . '                <div class="bg-pulse-grey w-100"></div>
';
        $buffer .= $indent . '            </div>
';
        $buffer .= $indent . '        </li>
';
        $buffer .= $indent . '    </ul>
';
        $buffer .= $indent . '</div>
';
        $value = $context->find('js');
        $buffer .= $this->section39a18ecdb3a2ce949c76033564c01a4f($context, $indent, $value);

        return $buffer;
    }

    private function section39a18ecdb3a2ce949c76033564c01a4f(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
require([\'core_courseformat/local/courseindex/placeholder\'], function(component) {
    component.init(\'#course-index-placeholder\');
});
';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= $indent . 'require([\'core_courseformat/local/courseindex/placeholder\'], function(component) {
';
                $buffer .= $indent . '    component.init(\'#course-index-placeholder\');
';
                $buffer .= $indent . '});
';
                $context->pop();
            }
        }
    
        return $buffer;
    }

}
