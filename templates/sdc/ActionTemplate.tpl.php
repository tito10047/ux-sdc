{% extends <?= var_export($layout, true) ?> %}

{% block <?= $block ?> %}
    <twig:<?= $full_twig_component_name ?> />
{% endblock %}
