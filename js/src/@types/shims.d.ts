// Allow .less imports to be referenced from TS without an explicit
// type — the bundler stubs them out at build time.
declare module '*.less' {
  const content: string;
  export default content;
}
