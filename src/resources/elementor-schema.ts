export const ELEMENTOR_SCHEMA = {
  description:
    "Elementor stores page layout as a JSON array in wp_postmeta._elementor_data. Each node has a unique id, an elType, settings, and a nested elements array.",
  node: {
    id: "7-char lowercase alphanumeric, unique per page (e.g. 'a1b2c3d')",
    elType: "'container' | 'widget'",
    settings: "object of element-specific settings (see widgets below)",
    elements: "array of child nodes (containers/widgets); empty for leaf widgets",
    widgetType: "present only when elType='widget' (e.g. 'heading','button','image')",
  },
  containerSettings: {
    container_type: "'flex' | 'grid'",
    flex_direction: "'row' | 'column' | 'row-reverse' | 'column-reverse'",
    flex_gap: { unit: "px", size: 20 },
    content_width: "'boxed' | 'full'",
    width: { unit: "%", size: 100 },
  },
  examples: {
    heading: { id: "h1aaaaa", elType: "widget", widgetType: "heading", settings: { title: "Hello", header_size: "h2" }, elements: [] },
    button: { id: "b1aaaaa", elType: "widget", widgetType: "button", settings: { text: "Click me", link: { url: "#" } }, elements: [] },
    image: { id: "i1aaaaa", elType: "widget", widgetType: "image", settings: { image: { url: "https://example.com/x.jpg" } }, elements: [] },
    threeColumnRow: {
      id: "rowaaaa",
      elType: "container",
      settings: { container_type: "flex", flex_direction: "row", flex_gap: { unit: "px", size: 20 } },
      elements: [
        { id: "colaaa1", elType: "container", settings: { width: { unit: "%", size: 33 } }, elements: [] },
        { id: "colaaa2", elType: "container", settings: { width: { unit: "%", size: 33 } }, elements: [] },
        { id: "colaaa3", elType: "container", settings: { width: { unit: "%", size: 33 } }, elements: [] },
      ],
    },
  },
  rules: [
    "Top-level _elementor_data is an ARRAY of container nodes.",
    "Widgets are always leaves: their elements array is empty.",
    "Every id must be unique within the page.",
    "After writing _elementor_data, set _elementor_edit_mode='builder' (the update_elementor_layout tool does this for you).",
  ],
};
