// Imports de .less sem tipo explícito: o bundler os resolve no build.
declare module '*.less' {
  const content: string;
  export default content;
}
