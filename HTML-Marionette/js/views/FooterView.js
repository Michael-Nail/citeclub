/*
 *
 * Author     : nweingartner
 * Date       : 7/20/2013
 * Description: Footer View.
 *
 */


define([
    "common",
    "backbone",
    "marionette"
    ],
    function(Common, Backbone, Marionette) {

        var CC = Common.CC || {};

        CC.Views.FooterView = Backbone.Marionette.ItemView.extend({

            template: "#footer_view_template"

        });
});