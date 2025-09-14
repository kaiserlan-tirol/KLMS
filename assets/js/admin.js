import $ from "jquery";

import './modules/adminDataTable/jquery.adminDataTable.js';
import './modules/cateringOrder/cateringOrder.js';
import './modules/sidebarPersistence/sidebarPersistence.js';

$( document ).ready(function() {
    $('.admin-data-table').AdminDataTable();
});
