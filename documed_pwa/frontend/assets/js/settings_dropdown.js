// Enable sidebar settings dropdown on all admin pages

document.addEventListener('DOMContentLoaded', function() {
  // Support multiple dropdowns if sidebar is duplicated
  var dropdowns = document.querySelectorAll('#settingsDropdown');
  var menus = document.querySelectorAll('#settingsMenu');
  var arrows = document.querySelectorAll('#settingsArrow');
  dropdowns.forEach(function(dropdown, i) {
    var menu = menus[i];
    var arrow = arrows[i];
    if (dropdown && menu && arrow) {
      dropdown.addEventListener('click', function(e) {
        e.stopPropagation();
        var isOpen = menu.style.display === 'block';
        menu.style.display = isOpen ? 'none' : 'block';
        arrow.style.transform = isOpen ? 'rotate(0deg)' : 'rotate(180deg)';
      });
      document.addEventListener('click', function(e) {
        if (!dropdown.contains(e.target)) {
          menu.style.display = 'none';
          arrow.style.transform = 'rotate(0deg)';
        }
      });
    }
  });
});
