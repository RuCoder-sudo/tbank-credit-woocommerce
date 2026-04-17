/**
 * Т-Банк Рассрочка и Кредит для WooCommerce — frontend script v2.0.0
 * Автор: Сергей Солошенко (RuCoder) — https://рукодер.рф/
 *
 * Функции:
 * - Интерактивный калькулятор рассрочки (кнопки по периодам)
 * - Обновление цены кнопки при смене вариации товара
 * - Обновление информера при смене вариации
 * - Tooltip на методе оплаты в чекауте
 * - Проверка минимальной суммы (UX-уведомление)
 */
(function ($) {
    'use strict';

    var TBankCredit = {

        config: window.tbankCreditData || {},

        init: function () {
            TBankCredit.initCalculator();
            TBankCredit.initVariationUpdate();

            $(document.body).on('updated_cart_totals', function () {
                TBankCredit.checkMinAmount();
            });

            $(document.body).on('updated_checkout', function () {
                TBankCredit.checkMinAmount();
            });

            TBankCredit.checkMinAmount();
        },

        // ── Installment period calculator ──────────────────────────────────────

        initCalculator: function () {
            $(document).on('click', '.tbank-credit-calculator__period-btn', function () {
                var $btn     = $(this);
                var $calc    = $btn.closest('.tbank-credit-calculator');
                var months   = parseInt($btn.data('months'), 10);
                var price    = parseFloat($calc.data('price'));
                var monthly  = Math.ceil(price / months);

                // Highlight active.
                $calc.find('.tbank-credit-calculator__period-btn').removeClass('is-active');
                $btn.addClass('is-active');

                // Update the informer text.
                var $informer = $calc.closest('form, .product').find('.tbank-credit-informer__text');
                if ($informer.length) {
                    var rawText = $informer.data('template') || $informer.text();
                    if (!$informer.data('template')) {
                        $informer.data('template', rawText);
                    }
                    var formatted = TBankCredit.formatPrice(monthly);
                    $informer.text(rawText.replace(/от [\d\s]+ ₽\/мес/, 'от ' + formatted + ' ₽/мес'));
                }
            });

            // Activate first period by default.
            $('.tbank-credit-calculator').each(function () {
                $(this).find('.tbank-credit-calculator__period-btn').first().trigger('click');
            });
        },

        // ── Variable product variation update ─────────────────────────────────

        initVariationUpdate: function () {
            $(document).on('found_variation', function (e, variation) {
                if (!variation || !variation.display_price) {
                    return;
                }

                var price = parseFloat(variation.display_price);
                var minAmount = parseFloat(TBankCredit.config.minAmount) || 3000;

                var $productForm = $(e.target).closest('form.variations_form, .product');

                // Update informer.
                var $informer = $productForm.find('.tbank-credit-informer');
                if ($informer.length) {
                    var months = parseInt($informer.data('months'), 10) || 6;
                    var monthly = Math.ceil(price / months);
                    var $text = $informer.find('.tbank-credit-informer__text');
                    var tmpl = $text.data('template') || $text.text();
                    if (!$text.data('template')) {
                        $text.data('template', tmpl);
                    }
                    $text.text(tmpl.replace(/{amount}|от [\d\s]+ ₽\/мес/, 'от ' + TBankCredit.formatPrice(monthly) + ' ₽/мес'));
                    $informer.data('price', price);
                    $informer.toggle(price >= minAmount);
                }

                // Update calculator.
                var $calc = $productForm.find('.tbank-credit-calculator');
                if ($calc.length) {
                    $calc.data('price', price);
                    $calc.find('.tbank-credit-calculator__period-btn').each(function () {
                        var m = parseInt($(this).data('months'), 10);
                        var mon = Math.ceil(price / m);
                        $(this).data('monthly', mon);
                        $(this).find('.tbank-credit-calculator__price').text(TBankCredit.formatPrice(mon) + ' ₽/мес');
                    });
                    $calc.toggle(price >= minAmount);
                }

                // Update direct button URL amount parameter.
                var $btn = $productForm.find('.tbank-credit-product-btn-wrap .tbank-credit-btn');
                if ($btn.length && price >= minAmount) {
                    var href = $btn.attr('href');
                    if (href) {
                        // Replace or add the sum parameter.
                        var amountInCents = Math.round(price * 100);
                        href = TBankCredit.updateQueryParam(href, 'sum', amountInCents);
                        $btn.attr('href', href);
                        $btn.attr('data-amount', price);
                    }
                    $btn.closest('.tbank-credit-product-btn-wrap').show();
                } else if ($btn.length) {
                    $btn.closest('.tbank-credit-product-btn-wrap').hide();
                }
            });

            // Reset on variation reset.
            $(document).on('reset_data', function (e) {
                var $productForm = $(e.target).closest('form.variations_form, .product');
                $productForm.find('.tbank-credit-informer').hide();
                $productForm.find('.tbank-credit-calculator').hide();
                $productForm.find('.tbank-credit-product-btn-wrap').hide();
            });
        },

        // ── Checkout: gateway availability hint ───────────────────────────────

        checkMinAmount: function () {
            var $method = $('li.payment_method_tbank_credit');
            if ($method.length === 0) return;

            var minAmount = parseFloat(TBankCredit.config.minAmount) || 3000;
            $method.find('label').attr(
                'title',
                'Оформление в рассрочку или кредит через Т-Банк. Минимальная сумма — ' +
                TBankCredit.formatPrice(minAmount) + ' ₽.'
            );
        },

        // ── Helpers ───────────────────────────────────────────────────────────

        formatPrice: function (n) {
            return Math.ceil(n).toLocaleString('ru-RU');
        },

        updateQueryParam: function (url, key, value) {
            var re  = new RegExp('([?&])' + key + '=[^&]*');
            var sep = url.indexOf('?') !== -1 ? '&' : '?';
            if (re.test(url)) {
                return url.replace(re, '$1' + key + '=' + encodeURIComponent(value));
            }
            return url + sep + key + '=' + encodeURIComponent(value);
        }
    };

    $(document).ready(function () {
        TBankCredit.init();
    });

})(jQuery);
