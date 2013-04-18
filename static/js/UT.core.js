var UT = {
    namespace: function(ns) {
        var parts = ns.split("."),
        object = this, i, len;

        for (i=0, len=parts.length; i < len; i++) {
            if (!object[parts[i]]) {
                object[parts[i]] = {};
            }
            object = object[parts[i]];
        }
        return object;
    },
    find_salon_name: function(site_id) {
      var salon_name = "";
      for(i=0; i< UT.reports.salons.length; i++) {
          if (site_id == UT.reports.salons[i][0]) {
              salon_name = UT.reports.salons[i][2];
              break;
          }
      }
      return salon_name;
    },
    max_salon: 100,
    salon_info_url: 'http://store-mail.ultratans.com/salon_info.php?single_name=true'
};

