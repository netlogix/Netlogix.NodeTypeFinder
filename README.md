# Netlogix.NodeTypeFinder
Neos Backend Module to search for occurrences of NodeTypes in the page tree

## Filters

The backend module narrows the search down before it runs:

* **Site**: Only the selected site is searched. Prefilled with the site of the currently
  requested domain, falling back to the default site. `All sites` searches every site.
* **Dimensions**: Only the selected dimension combination is searched. Prefilled with the
  default preset of every configured content dimension. `All dimensions` searches every
  allowed combination. The field is hidden if no content dimensions are configured.

The CLI command `nodetypefinder:listnodetypeoccurrences` is unfiltered and searches all
sites and all dimension combinations.
