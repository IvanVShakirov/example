define(function (require) {
    const $ = require('jquery');
    const _ = require('underscore');

    const {widget : {version : VERSION}} = require('json!./manifest.json');

    /**
     * Код виджета в директориях
     *
     * @type {string}
     */
    const CODE = 'somecode';

    /**
     * Путь до виджета
     *
     * @type {string}
     */
    const WIDGET_PATH = `upl/${CODE}/widget`;

    /**
     * Путь к шаблонам
     *
     * @type {string}
     */
    const BASE_TEMPLATES = `${WIDGET_PATH}/templates/`;

    /**
     * Путь к таблицам стилей
     *
     * @type {string}
     */
    const CSS_PATH = `/${WIDGET_PATH}/css/`;

    const BASE_URL = 'https://core.company.ru/Example';

    const POST_LEAD_ID_URL = `${BASE_URL}/create_lead/send_to_Example_crm/example`;

    const AREA_LEAD_CARD = 'leads.card';

    let currentArea = null;

    const getCurrentArea = function () {
        return AMOCRM.getV3WidgetsArea();
    };

    const sendLeadId = function sendLeadId(leadId) {
        return $.post(POST_LEAD_ID_URL, JSON.stringify({lead_id : leadId})).promise();
    };

    return function Widget() {
        const self = this;

        /**
         * Возвращает html на основе встроенного twig-шаблона
         *
         * @param {string}   templateName Имя шаблона
         *
         * @return {Promise}
         */
        const getTemplate = function getTemplate(templateName) {
            return new Promise((resolve) => {
                self.render({
                    href      : BASE_TEMPLATES + templateName + '.twig',
                    base_path : '/',
                    v         : VERSION,
                    load      : template => resolve({
                        render : (data = {}) =>
                            template.render({
                                ...data,
                                baseTemplates : BASE_TEMPLATES,
                                i18n          : self.i18n.bind(self),
                            }),
                    }),
                })
                ;
            });
        };

        /**
         * Преобразование Объекта JS в строкое представление
         * пригодное для вставки аттрибутов в NODE элемент
         *
         * @param obj Преобразуемый объект
         *
         * @return {string}
         */
        const objectToNodeAttrs = function objectToNodeAttrs(obj) {
            return _.reduce(
                obj,
                function (result, value, key) {
                    return `${result} ${key}=${value}`;
                },
                '',
            );
        };

        /**
         * Подключает css файл если он ранее не был подключен
         *
         * @param {string} cssPath Путь до подключаемого файла
         * @param {Object} options Дополнительные опции подключения
         *
         * @return {boolean}
         */
        const includeCss = function includeCss(cssPath, options = {}) {
            if ($(`link[href="${cssPath}"]`).length) {
                return false;
            }

            const optionsToString = objectToNodeAttrs(options);

            $('head').append(`<link rel="stylesheet" href="${cssPath}" type="text/css" ${optionsToString}/>`);

            return true;
        };

        /**
         * Подключает css файл если он ранее не был подключен
         *
         * @param {...string} fileNames Имя подключаемого файла из папки css
         *
         * @return {boolean}
         */
        const includeWidgetCss = function includeWidgetCss(...fileNames) {
            fileNames.forEach(fileName => includeCss(`${CSS_PATH}${fileName}.css?v=${VERSION}`));
        };

        this.callbacks = {
            settings($widgetDescr) {
                $('#widget_settings__fields_wrapper', $widgetDescr).hide();
            },
            init() {
                return true;
            },
            bind_actions() {
                $(document)
                    .off(self.ns)
                    .on(AMOCRM.click_event + self.ns, '#button_create_lead', function () {
                        sendLeadId(AMOCRM.data.current_card.id);
                    });

                return true;
            },
            render() {
                currentArea = getCurrentArea();
                if (currentArea !== AREA_LEAD_CARD) {
                    return true;
                }

                includeWidgetCss('widget');
                getTemplate('widget_li')
                    .then(function (liTemplate) {
                        $('#card_name_holder')
                            .find('.button-input__context-menu')
                            .append(
                                liTemplate.render(),
                            );
                    });

                return true;
            },
            destroy() {
            },
            onSave() {
                return true;
            },
        };

        return this;
    };
});