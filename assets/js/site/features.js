
import $ from 'jquery';
import 'bootstrap';

$(function () {
    if ($('#checklist-container').length) {
      // Load checklist items from local storage
      function loadChecklist() {
        const checklistItems = JSON.parse(localStorage.getItem('checklistItems')) || {};
        for (const id in checklistItems) {
            if (checklistItems.hasOwnProperty(id)) {
                $(`#${id}`).prop('checked', checklistItems[id]);
            }
        }
      }

      // Save checklist items to local storage
      function saveChecklist() {
          const checklistItems = {};
          $('.checkbox-item input[type="checkbox"]').each(function() {
              const id = $(this).attr('id');
              const isChecked = $(this).prop('checked');
              checklistItems[id] = isChecked;
          });
          localStorage.setItem('checklistItems', JSON.stringify(checklistItems));
      }

      // Event listener for checkbox changes
      $('.checkbox-item input[type="checkbox"]').change(function() {
          saveChecklist();
      });

      // Call functions on page load
      loadChecklist();
    }
})